<?php

declare(strict_types=1);

use Pushery\FortifyHardening\Http\Requests\RegistrationRequest;
use Pushery\FortifyHardening\Notifications\AccountAlreadyExistsNotification;

return [

    /*
    |--------------------------------------------------------------------------
    | Fortify Hardening for Laravel
    |--------------------------------------------------------------------------
    |
    | Laravel Fortify tells a caller, at three places, whether an email address
    | has an account: the registration form, the password reset link and the
    | password reset itself. Each section below closes one of them and can be
    | switched off on its own, and the last one closes the difference in time.
    | Published to config/fortify-hardening.php with
    | `php artisan vendor:publish --tag=fortify-hardening-config`.
    |
    | A section is switched off with `false`, `0`, `no`, `off` or an empty
    | value, in any case and with spaces around it. Any other value keeps it
    | on, and so does a variable that is not set.
    |
    | Read environment values with env() HERE and nowhere else. Outside a config
    | file env() returns null the moment the host app caches its config, so the
    | value silently collapses to its default in production and nowhere else.
    |
    */

    /*
    | The reset-link request answers an unknown address, and an address that
    | asked too recently, exactly as it answers a link that was sent.
    */
    'password_reset_link' => [
        'enabled' => ! in_array(
            is_string($resetLink = env('FORTIFY_HARDENING_PASSWORD_RESET_LINK')) ? strtolower(trim($resetLink)) : $resetLink,
            [false, '', '0', 'false', 'no', 'off'],
            true,
        ),
    ],

    /*
    | The password reset answers an unknown address exactly as it answers a
    | known address whose token is wrong or expired.
    */
    'password_reset' => [
        'enabled' => ! in_array(
            is_string($reset = env('FORTIFY_HARDENING_PASSWORD_RESET')) ? strtolower(trim($reset)) : $reset,
            [false, '', '0', 'false', 'no', 'off'],
            true,
        ),
    ],

    /*
    | The registration answers a free address and a taken one the same way and
    | signs nobody in. A taken address gets no second account, and its holder
    | gets the notice below instead.
    */
    'registration' => [
        'enabled' => ! in_array(
            is_string($registration = env('FORTIFY_HARDENING_REGISTRATION')) ? strtolower(trim($registration)) : $registration,
            [false, '', '0', 'false', 'no', 'off'],
            true,
        ),

        // The form request that validates a registration before the address is
        // looked up. It must not carry a `unique` rule on the address, and its
        // rules should be the ones your CreatesNewUsers action checks.
        'request' => RegistrationRequest::class,

        // Where the neutral answer redirects a web request to, or the application's
        // root when no route of that name exists.
        'redirect_route' => 'login',

        // Where the notice to the holder of a taken address links to. When no
        // route of that name exists, the notice links to the application's root.
        'sign_in_route' => 'login',

        // The notice the holder of a taken address receives, and its channels.
        'notification' => AccountAlreadyExistsNotification::class,
        'notice_channels' => ['mail'],

        // How many mails the registration form may send to one address within
        // an hour, the verification link of a new account included. Read typed
        // and held to a floor of 1: a value no ceiling can use, such as `abc`,
        // `0` or `-3`, lands on the default rather than on no ceiling at all.
        'max_mails_per_hour' => is_numeric($maxMailsPerHour = env('FORTIFY_HARDENING_MAX_MAILS_PER_HOUR')) && (int) $maxMailsPerHour >= 1
            ? (int) $maxMailsPerHour
            : 1,
    ],

    /*
    | Laravel holds a failed sign-in, a reset-link request and a failed password
    | reset to a minimum duration, `auth.timebox_duration`, so that an address
    | without an account cannot answer sooner than one with an account. Its
    | default of 200 ms is shorter than the bcrypt hash an existing account
    | costs at Laravel's 12 rounds, so the difference still shows. The package
    | raises the minimum to the value below, in microseconds, unless your
    | application sets `auth.timebox_duration` itself. With Fortify's two-factor
    | feature on, Fortify checks the password in a step of its own first and
    | holds a failure there to 200 ms; the package holds that step to the same
    | minimum. A successful sign-in or reset still returns at once. `0` leaves
    | Laravel's default in place.
    */
    'timebox' => [
        'microseconds' => is_numeric($timebox = env('FORTIFY_HARDENING_TIMEBOX_MICROSECONDS')) && (int) $timebox >= 0
            ? (int) $timebox
            : 1_000_000,
    ],

    /*
    | Fortify counts failed sign-ins under the address and the client IP as
    | they are, so both stand in the cache key, in the cache store and in what
    | an error tracker records about cache calls. The package counts under the
    | same pair hashed with the application key, so the count and the limit
    | stay Fortify's and the key names nobody. Counts that run when you switch
    | it on or off start again.
    */
    'hashed_login_keys' => [
        'enabled' => ! in_array(
            is_string($hashedLoginKeys = env('FORTIFY_HARDENING_HASHED_LOGIN_KEYS')) ? strtolower(trim($hashedLoginKeys)) : $hashedLoginKeys,
            [false, '', '0', 'false', 'no', 'off'],
            true,
        ),
    ],

];
