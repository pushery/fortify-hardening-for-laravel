<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Support;

use Illuminate\Container\Container;

/**
 * The key a sign-in is counted under, hashed with the application key so that it names nobody.
 *
 * Fortify counts failed sign-ins per username and client IP in two places: its `LoginRateLimiter`, which the package
 * replaces, and the `login` rate limiter its installer writes into the application's `FortifyServiceProvider`, which
 * `fortify.limiters.login` puts on the sign-in route. Laravel keeps the count of that named limiter under an MD5 of
 * the limiter's name and its key, a value anyone can compute again from a guessed address and IP. Counted by the key
 * this method returns, it stands under the same HMAC the package's own limiter counts under:
 *
 *     RateLimiter::for('login', function (Request $request) {
 *         $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());
 *
 *         return Limit::perMinute(5)->by(LoginThrottleKey::hash($throttleKey));
 *     });
 */
final class LoginThrottleKey
{
    /** The throttle key as an HMAC under the application key: the same key gives the same value, and a guess cannot compute it. */
    public static function hash(string $throttleKey): string
    {
        return 'fortify-hardening:login:'.hash_hmac('sha256', $throttleKey, Container::getInstance()->make(Settings::class)->secret());
    }
}
