<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Support;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * The key a sign-in is counted under, hashed with the application key so that it names nobody.
 *
 * Fortify counts failed sign-ins per username and client IP in two places: its `LoginRateLimiter`, which the package
 * replaces, and the `login` rate limiter its installer writes into the application's `FortifyServiceProvider`, which
 * `fortify.limiters.login` puts on the sign-in route. Laravel keeps the count of that named limiter under an MD5 of
 * the limiter's name and its key, a value anyone can compute again from a guessed address and IP. Counted by the key
 * {@see self::of()} returns, it stands under the same HMAC the package's own limiter counts under:
 *
 *     RateLimiter::for('login', function (Request $request) {
 *         return Limit::perMinute(5)->by(LoginThrottleKey::of($request));
 *     });
 */
final class LoginThrottleKey
{
    /** The first twelve bytes of an IPv4 address in its IPv6 form, such as `::ffff:203.0.113.7`. */
    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * The key a sign-in attempt is counted under: the lower-cased username and the client it comes from, hashed.
     *
     * An IPv4 address counts as it is, and an IPv4 address in its IPv6 form as the IPv4 address it carries. An IPv6
     * client counts by its /64, the network a single host is usually given, so that a host cannot start a count of
     * its own from each address in it.
     */
    public static function of(Request $request): string
    {
        return self::hash(Str::transliterate(self::username($request).'|'.self::client($request->ip())));
    }

    /** The throttle key as an HMAC under the application key: the same key gives the same value, and a guess cannot compute it. */
    public static function hash(string $throttleKey): string
    {
        return 'fortify-hardening:login:'.hash_hmac('sha256', $throttleKey, Container::getInstance()->make(Settings::class)->secret());
    }

    /**
     * The username of an attempt, lower-cased. A value that is no single word, such as a list sent as `email[]=…`, reads
     * as an empty name: the `login` limiter above runs before the validation that refuses such a request, and a list
     * turned into text would end the request in an error page there.
     */
    private static function username(Request $request): string
    {
        $value = $request->input(Fortify::username());

        return is_scalar($value) ? Str::lower((string) $value) : '';
    }

    /** The client an attempt is counted for, by the rule {@see self::of()} states. */
    private static function client(?string $ip): string
    {
        if ($ip === null) {
            return '';
        }

        $packed = inet_pton($ip);

        // An IPv4 address counts as it is, and so does a value that is no address at all.
        if ($packed === false || strlen($packed) === 4) {
            return $ip;
        }

        if (str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
