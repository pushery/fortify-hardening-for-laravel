<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\FortifyHardening\Http\Requests\RegistrationRequest;
use Pushery\FortifyHardening\Notifications\AccountAlreadyExistsNotification;

/**
 * The package's settings, read with the defaults the shipped config file carries.
 *
 * Every read falls back to the shipped default when the key is missing or holds a value of the wrong
 * type. A host whose configuration is cached never has the package config merged in, so the
 * fallback here is what such a host runs on, and it has to agree with config/fortify-hardening.php.
 */
final readonly class Settings
{
    public const int DEFAULT_MAX_MAILS_PER_HOUR = 1;

    public const int DEFAULT_TIMEBOX_MICROSECONDS = 1_000_000;

    public function __construct(private Repository $config) {}

    public function neutralizesPasswordResetLink(): bool
    {
        return $this->boolean('fortify-hardening.password_reset_link.enabled', true);
    }

    public function neutralizesPasswordReset(): bool
    {
        return $this->boolean('fortify-hardening.password_reset.enabled', true);
    }

    public function hardensRegistration(): bool
    {
        return $this->boolean('fortify-hardening.registration.enabled', true);
    }

    /**
     * The form request that validates a registration, the same way for a free address and a taken one.
     */
    public function registrationRequest(): string
    {
        return $this->string('fortify-hardening.registration.request', RegistrationRequest::class);
    }

    /**
     * The route the neutral answer to a registration redirects to.
     */
    public function redirectRoute(): string
    {
        return $this->string('fortify-hardening.registration.redirect_route', 'login');
    }

    /**
     * The route the notice to the holder of a taken address links to.
     */
    public function signInRoute(): string
    {
        return $this->string('fortify-hardening.registration.sign_in_route', 'login');
    }

    /**
     * The notification the holder of a taken address receives.
     */
    public function noticeNotification(): string
    {
        return $this->string('fortify-hardening.registration.notification', AccountAlreadyExistsNotification::class);
    }

    /**
     * The channels that notification goes out on.
     *
     * @return list<string>
     */
    public function noticeChannels(): array
    {
        $channels = $this->config->get('fortify-hardening.registration.notice_channels');

        if (! is_array($channels)) {
            return ['mail'];
        }

        $names = [];

        foreach ($channels as $channel) {
            if (is_string($channel)) {
                $names[] = $channel;
            }
        }

        return $names;
    }

    /**
     * How many mails the registration form may send to one address within an hour.
     */
    public function maxMailsPerHour(): int
    {
        $value = $this->config->get('fortify-hardening.registration.max_mails_per_hour');

        return is_int($value) && $value >= 1 ? $value : self::DEFAULT_MAX_MAILS_PER_HOUR;
    }

    /**
     * The minimum duration, in microseconds, of a failed sign-in, a reset-link request and a failed
     * password reset, or 0 to leave Laravel's own in place.
     */
    public function timeboxMicroseconds(): int
    {
        $value = $this->config->get('fortify-hardening.timebox.microseconds');

        return is_int($value) && $value >= 0 ? $value : self::DEFAULT_TIMEBOX_MICROSECONDS;
    }

    /**
     * The cache store Laravel's rate limiter counts in, `cache.limiter`, or null for the default
     * store.
     */
    public function limiterStore(): ?string
    {
        $value = $this->config->get('cache.limiter');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The guard Fortify signs users in with, whose user provider finds an existing account.
     */
    public function fortifyGuard(): string
    {
        return $this->string('fortify.guard', 'web');
    }

    /**
     * The user provider behind that guard, or null when the guard names none.
     */
    public function userProvider(): ?string
    {
        $provider = $this->config->get('auth.guards.'.$this->fortifyGuard().'.provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    public function lowercasesUsernames(): bool
    {
        return $this->boolean('fortify.lowercase_usernames', false);
    }

    private function boolean(string $key, bool $default): bool
    {
        $value = $this->config->get($key);

        return is_bool($value) ? $value : $default;
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
