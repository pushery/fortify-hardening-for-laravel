<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Support;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * The password resets a session completed a moment ago, so a reset form sent twice reads as the reset it repeats.
 *
 * A double click sends the reset form twice. The first request sets the password and the broker deletes the token,
 * so the second finds none and would be answered as a reset with an invalid token, although the password the person
 * just chose is already theirs. A completed reset is noted under its session and the address its form named, and a
 * refused reset is answered as the one it repeats where all of this holds: the note names the session and the address
 * of the form, the password and its confirmation agree, and the account that address leads to holds exactly that
 * password. Without the note a refused reset stays neutral, so the answer tells nobody anything about an account they
 * did not just reset themselves.
 *
 * The note is looked up before any account is read. A refused reset is answered after the broker's minimum duration
 * has ended, so work that only an address with an account costs would show in the time of the answer. Keyed by what
 * the request itself carries, the lookup is one cache read for any address, and an account is read only in a session
 * that reset the address of its form a moment ago.
 *
 * The note is kept in the cache rather than in the session, because the two requests run side by side and a session
 * is written only when its request ends. It is written while the broker resets, before the token is deleted, so a
 * second request that finds the token gone finds the note.
 *
 * Its key is an HMAC of the session id and the address under the application key. A session the `redis`, `memcached`
 * or `dynamodb` driver keeps stands in a cache store under its bare id, among the keys an error tracker or a store's
 * monitor records, and an unkeyed hash of the two would let whoever reads those keys try a guessed address against
 * every session.
 */
final readonly class CompletedPasswordResets
{
    /** How long a completed reset answers for a repeat of its form: long enough for a second click, and not for a later visit. */
    private const int REPEAT_WINDOW_SECONDS = 60;

    public function __construct(
        private Cache $cache,
        private Hasher $hasher,
        private PasswordBrokerFactory $brokers,
        private Config $config,
        private AuthManager $auth,
        private Settings $settings,
    ) {}

    /**
     * Note that the reset form of this request completed a reset, under its session and the address it named.
     *
     * A reset made without such a form, by a form of the application's that names no address or outside a request,
     * is not noted: no reset form can repeat it.
     */
    public function remember(Request $request): void
    {
        $email = $request->input(Fortify::email());

        if ($request->hasSession() && is_string($email)) {
            $this->cache->put($this->key($request->session()->getId(), $email), true, self::REPEAT_WINDOW_SECONDS);
        }
    }

    /**
     * Whether a refused reset repeats one this session completed a moment ago.
     *
     * The same address, a password its confirmation agrees with, and the password the account that address leads to
     * now holds. The note is written and read under the address of the form, which both requests of a double click
     * carry alike, also where an application keeps its reset tokens under another value, such as a shop's
     * `<scope>:<address>`.
     */
    public function repeats(Request $request): bool
    {
        $email = $request->input(Fortify::email());
        $password = $request->input('password');

        if (! $request->hasSession() || ! is_string($email) || ! is_string($password) || $password !== $request->input('password_confirmation')) {
            return false;
        }

        if ($this->cache->get($this->key($request->session()->getId(), $email)) !== true) {
            return false;
        }

        $user = $this->user($email);

        return $user instanceof Authenticatable && $this->hasher->check($password, $user->getAuthPassword());
    }

    /**
     * The account an address names, looked up where the broker Fortify resets with looks.
     *
     * Laravel's broker, and a broker of the application's that hands getUser() on to it, answer for themselves. A
     * broker that implements the contract alone cannot be asked, so the account comes from the user provider its
     * configuration names, which is where Laravel's broker looks it up.
     */
    private function user(string $email): ?Authenticatable
    {
        $name = $this->brokerName();
        $broker = $this->brokers->broker($name);
        $credentials = [Fortify::email() => $email];

        $user = method_exists($broker, 'getUser')
            ? $broker->getUser($credentials)
            : $this->auth->createUserProvider($this->providerOf($name))?->retrieveByCredentials($credentials);

        return $user instanceof Authenticatable ? $user : null;
    }

    /** The user provider a broker's configuration names, or null for the application's default provider. */
    private function providerOf(?string $broker): ?string
    {
        $provider = $this->config->get("auth.passwords.{$broker}.provider");

        return is_string($provider) ? $provider : null;
    }

    /** The cache key of a session and an address, an HMAC under the application key, so the cache holds neither and a guess cannot be hashed to find it. */
    private function key(string $session, string $email): string
    {
        return 'fortify-hardening:password-reset:'.hash_hmac('sha256', $session.'|'.Str::lower($email), $this->settings->secret());
    }

    /** The broker Fortify resets with, as its configuration names it. */
    private function brokerName(): ?string
    {
        $name = $this->config->get('fortify.passwords');

        return is_string($name) ? $name : null;
    }
}
