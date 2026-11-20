<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Laravel\Fortify\LoginRateLimiter;
use Override;
use Pushery\FortifyHardening\Actions\TimeboxedSignInStep;
use Pushery\FortifyHardening\Actions\TimeboxedTwoFactorStep;
use Pushery\FortifyHardening\Contracts\RegistrationAccepted;
use Pushery\FortifyHardening\Http\Controllers\EnumerationSafeRegisteredUserController;
use Pushery\FortifyHardening\Http\Responses\NeutralPasswordResetLinkResponse;
use Pushery\FortifyHardening\Http\Responses\NeutralPasswordResetResponse;
use Pushery\FortifyHardening\Http\Responses\NeutralRegistrationResponse;
use Pushery\FortifyHardening\Listeners\HoldBackVerificationMail;
use Pushery\FortifyHardening\Listeners\RememberCompletedPasswordReset;
use Pushery\FortifyHardening\Support\HashedLoginRateLimiter;
use Pushery\FortifyHardening\Support\LanguageFallbackLoader;
use Pushery\FortifyHardening\Support\Settings;

/**
 * Puts the package's answers in place of Fortify's, and ships its configuration and translations.
 *
 * The package adds no table and no route: it changes what Fortify's own routes answer. Its one view is
 * the mail template the notice to the holder of a taken address uses in an application named Laravel.
 */
final class FortifyHardeningServiceProvider extends ServiceProvider
{
    /**
     * The sections of the config that are catalogs, as dotted paths from the config key: maps whose
     * keys the host chooses, one entry per plan, per document or per signing key. A key that a
     * published catalog leaves out was removed by the host rather than added by a later release, so
     * the merge takes a published catalog as it stands. A catalog the host never published arrives
     * whole from the default. A package lists such a section here, for example
     * 'fortify-hardening.plans'.
     *
     * @var list<string>
     */
    private const array CATALOG_SECTIONS = [];

    #[Override]
    public function register(): void
    {
        $this->mergeConfigRecursivelyFrom(__DIR__.'/../config/fortify-hardening.php', 'fortify-hardening');

        // A regional locale reads this package's catalog of its language, `pt-BR` from `pt`, in this package's
        // namespace only. Registered here so it wraps the loader before the translator is built from it.
        $this->app->extend('translation.loader', static fn (Loader $loader): Loader => new LanguageFallbackLoader($loader, 'fortify-hardening'));

        $this->app->bindIf(RegistrationAccepted::class, NeutralRegistrationResponse::class);

        $this->raiseTheTimebox();
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'fortify-hardening');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'fortify-hardening');

        $this->bindInPlaceOfFortify();

        $this->holdTheSignInStepsToTheTimebox();

        // The verification mail of an account the registrar created over the mail budget is stopped as it is
        // about to be sent, in the request or in the queue worker that sends it. Listened to whether or not the
        // registration on Fortify's route is switched on, since a controller of the application's own calls the
        // registrar as well.
        $this->app->make(Dispatcher::class)->listen(NotificationSending::class, HoldBackVerificationMail::class);

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
        }
    }

    /**
     * Hold a failed sign-in, a reset-link request and a failed password reset to the package's
     * minimum duration, unless the application sets Laravel's own.
     *
     * Laravel reads `auth.timebox_duration` when it builds a guard or a password broker, and a
     * provider may build one while it boots, so the value is in place before the first provider
     * boots. A host whose configuration is cached gets the value the cache was built with. A
     * minimum the application reads from `env()` arrives as a string of digits, and Laravel takes
     * it as the number it spells, so it counts as set and stays.
     */
    private function raiseTheTimebox(): void
    {
        $repository = $this->app->make(Repository::class);
        $settings = new Settings($repository);
        $microseconds = $settings->timeboxMicroseconds();

        if ($microseconds > 0 && ! $settings->guardTimeboxIsSet()) {
            $repository->set('auth.timebox_duration', $microseconds);
        }
    }

    /**
     * Hold the steps of Fortify's sign-in that check a password to the minimum the guard reads,
     * unless the package's minimum is switched off.
     *
     * With the two-factor feature on, Fortify's two-factor step checks the password before the
     * guard does and holds a failure to a fixed 200 milliseconds of its own. The step is wrapped
     * where Fortify's contract resolves, so one the application binds with
     * `Fortify::redirectUserForTwoFactorAuthenticationUsing()` is held as well. A login pipeline of
     * the application's own that names a class instead of the contract is left as it is.
     *
     * With a callback set through `Fortify::authenticateUsing()`, Fortify's `AttemptToAuthenticate`
     * checks the password through that callback and never through the guard, so nothing else holds
     * its failure. That step is wrapped where its class resolves, which is also where a login
     * pipeline of the application's own that names the class resolves it.
     */
    private function holdTheSignInStepsToTheTimebox(): void
    {
        if ($this->app->make(Settings::class)->timeboxMicroseconds() === 0) {
            return;
        }

        $this->app->extend(
            RedirectsIfTwoFactorAuthenticatable::class,
            static fn (RedirectsIfTwoFactorAuthenticatable $step, Container $app): TimeboxedTwoFactorStep => new TimeboxedTwoFactorStep($step, $app->make(Settings::class)),
        );

        $this->app->extend(
            AttemptToAuthenticate::class,
            static fn (AttemptToAuthenticate $step, Container $app): TimeboxedSignInStep => new TimeboxedSignInStep($step, $app->make(Settings::class)),
        );
    }

    /**
     * Put the package's answers in place of Fortify's, each behind its switch.
     *
     * Bound while booting rather than while registering: Fortify binds its own responses while it
     * registers, and every provider has registered before the first one boots, so these bindings
     * stand whichever order the providers come in.
     */
    private function bindInPlaceOfFortify(): void
    {
        $settings = $this->app->make(Settings::class);

        if ($settings->neutralizesPasswordResetLink()) {
            $this->app->bind(FailedPasswordResetLinkRequestResponse::class, NeutralPasswordResetLinkResponse::class);
        }

        if ($settings->neutralizesPasswordReset()) {
            $this->app->bind(FailedPasswordResetResponse::class, NeutralPasswordResetResponse::class);

            // The note a reset form sent twice is recognized by, written while the broker still holds the token.
            $this->app->make(Dispatcher::class)->listen(PasswordReset::class, RememberCompletedPasswordReset::class);
        }

        // The registration only where people sign in with their address. With a username of its own, a taken
        // username has to be refused for the person to pick another, and the package's controller would reach
        // that refusal for a free address alone, which tells a free address from a taken one. Fortify's
        // controller stays in place there.
        if ($settings->hardensRegistration() && $settings->signsInWithTheAddress()) {
            $this->app->bind(RegisteredUserController::class, EnumerationSafeRegisteredUserController::class);
        }

        if ($settings->hashesLoginKeys()) {
            $this->app->bind(LoginRateLimiter::class, HashedLoginRateLimiter::class);
        }
    }

    /**
     * Lay the shipped defaults under a published config file, all the way down.
     *
     * Laravel's own `mergeConfigFrom()` is a single `array_merge` at the top level, so it asks
     * one question per top-level key: is it there? A host that published the config file has
     * every top-level key, so a key that a later release adds inside one of those blocks would
     * never arrive, and the block the host published would win whole.
     *
     * Nothing would error or log: the new setting would read as `null` (or as `[]`). A feature
     * added in a minor release would be off for exactly the hosts that customized that area, and
     * a corrected security default would not take effect, for as long as the host keeps the file
     * it published.
     *
     * Recursion stops at a list on either side, because a list is a value, not a structure.
     * `array_merge_recursive()` concatenates lists and `array_replace_recursive()` merges them by
     * index: a host narrowing a shipped `['web', 'api']` to `['admin']` gets back
     * `['web', 'api', 'admin']` from the first and `['admin', 'api']` from the second. Both hand
     * back an entry the host removed, which on an allowlist is a security regression. Here the
     * published list stands as written.
     *
     * `array_is_list([])` is true, which is the behavior wanted here: an empty array is a host
     * saying "none", and descending into it could only re-introduce what it emptied.
     *
     * Recursion stops at a catalog as well, a section named in CATALOG_SECTIONS. Its keys are the
     * host's, so a key missing from the published catalog is one the host removed, and laying the
     * default under it would hand that entry back.
     *
     * A host with a cached config is not merged at runtime: the cache already holds the merge
     * `config:cache` did when it ran. A key a later release adds is missing from it until the host
     * caches again, so a package that reads a nested key needs its read-site fallback to agree with
     * the shipped default.
     */
    private function mergeConfigRecursivelyFrom(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $shipped = require $path;

        $repository = $this->app->make(Repository::class);
        $existing = $repository->get($key);

        // Both sides come off disk or out of the container, so neither is provably
        // string-keyed here — a config array is just an array. The recursion below is
        // written for exactly that: it asks whether a value is a LIST, never whether a key
        // is a string.
        $repository->set($key, $this->mergeConfigSections(
            is_array($shipped) ? $shipped : [],
            is_array($existing) ? $existing : [],
            $key.'.',
            self::CATALOG_SECTIONS,
        ));
    }

    /**
     * @param  array<array-key, mixed>  $shipped
     * @param  array<array-key, mixed>  $published
     * @param  string  $path  the dotted path of the two maps, so a catalog is recognized at any depth
     * @param  list<string>  $catalogs  the dotted paths of the catalogs, which the merge takes as published
     * @return array<array-key, mixed>
     */
    private function mergeConfigSections(array $shipped, array $published, string $path = '', array $catalogs = []): array
    {
        foreach ($shipped as $key => $value) {
            if (! array_key_exists($key, $published)) {
                $published[$key] = $value;

                continue;
            }

            // Recurse only where both sides are maps and the section is no catalog. If either is a
            // list, or the published value is a scalar or an explicit null, what the host wrote
            // stands. An explicit null is a value like any other here, which keeps "write => null
            // to switch this off" working exactly as a shipped config file describes it.
            if (is_array($value) && is_array($published[$key]) && ! array_is_list($value) && ! array_is_list($published[$key]) && ! in_array($path.$key, $catalogs, true)) {
                $published[$key] = $this->mergeConfigSections($value, $published[$key], $path.$key.'.', $catalogs);
            }
        }

        return $published;
    }

    private function registerPublishing(): void
    {
        // Each group carries the bare 'fortify-hardening' umbrella tag on top of its specific one,
        // so `vendor:publish --tag="fortify-hardening"` publishes the config and the translations
        // at once, the tag convention Laravel's official package skeleton establishes.
        $this->publishes([
            __DIR__.'/../config/fortify-hardening.php' => $this->app->configPath('fortify-hardening.php'),
        ], ['fortify-hardening', 'fortify-hardening-config']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/fortify-hardening'),
        ], ['fortify-hardening', 'fortify-hardening-lang']);
    }
}
