<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Laravel\Fortify\LoginRateLimiter;
use Override;
use Pushery\FortifyHardening\Actions\TimeboxedTwoFactorStep;
use Pushery\FortifyHardening\Contracts\RegistrationAccepted;
use Pushery\FortifyHardening\Http\Controllers\EnumerationSafeRegisteredUserController;
use Pushery\FortifyHardening\Http\Responses\NeutralPasswordResetLinkResponse;
use Pushery\FortifyHardening\Http\Responses\NeutralPasswordResetResponse;
use Pushery\FortifyHardening\Http\Responses\NeutralRegistrationResponse;
use Pushery\FortifyHardening\Support\HashedLoginRateLimiter;
use Pushery\FortifyHardening\Support\LanguageFallbackLoader;
use Pushery\FortifyHardening\Support\Settings;

/**
 * Puts the package's answers in place of Fortify's, and ships its configuration and translations.
 *
 * The package adds no table, no route and no view: it changes what Fortify's own routes answer.
 */
final class FortifyHardeningServiceProvider extends ServiceProvider
{
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

        $this->bindInPlaceOfFortify();

        $this->holdTheTwoFactorStepToTheTimebox();

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
     * boots. A host whose configuration is cached gets the value the cache was built with.
     */
    private function raiseTheTimebox(): void
    {
        $repository = $this->app->make(Repository::class);
        $microseconds = new Settings($repository)->timeboxMicroseconds();

        if ($microseconds > 0 && ! is_int($repository->get('auth.timebox_duration'))) {
            $repository->set('auth.timebox_duration', $microseconds);
        }
    }

    /**
     * Hold Fortify's two-factor step of the sign-in to the minimum the guard reads, unless the
     * package's minimum is switched off.
     *
     * With the two-factor feature on, that step checks the password before the guard does and
     * holds a failure to a fixed 200 milliseconds of its own. The step is wrapped where Fortify's
     * contract resolves, so one the application binds with
     * `Fortify::redirectUserForTwoFactorAuthenticationUsing()` is held as well. A login pipeline of
     * the application's own that names a class instead of the contract is left as it is.
     */
    private function holdTheTwoFactorStepToTheTimebox(): void
    {
        if ($this->app->make(Settings::class)->timeboxMicroseconds() === 0) {
            return;
        }

        $this->app->extend(
            RedirectsIfTwoFactorAuthenticatable::class,
            static fn (RedirectsIfTwoFactorAuthenticatable $step, Container $app): TimeboxedTwoFactorStep => new TimeboxedTwoFactorStep($step, $app->make(Repository::class)),
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
        }

        if ($settings->hardensRegistration()) {
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
     * A host with a cached config is never merged at all, by the framework's design. For those
     * installations the published file is the whole truth, so a package that reads a nested key
     * needs its read-site fallback to agree with the shipped default. The two are halves of one
     * guarantee.
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
        ));
    }

    /**
     * @param  array<array-key, mixed>  $shipped
     * @param  array<array-key, mixed>  $published
     * @return array<array-key, mixed>
     */
    private function mergeConfigSections(array $shipped, array $published): array
    {
        foreach ($shipped as $key => $value) {
            if (! array_key_exists($key, $published)) {
                $published[$key] = $value;

                continue;
            }

            // Recurse only where BOTH sides are maps. If either is a list, or the published
            // value is a scalar or an explicit null, what the host wrote stands. An explicit
            // null is a value like any other here, which keeps "write => null to switch this
            // off" working exactly as a shipped config file describes it.
            if (is_array($value) && is_array($published[$key]) && ! array_is_list($value) && ! array_is_list($published[$key])) {
                $published[$key] = $this->mergeConfigSections($value, $published[$key]);
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
