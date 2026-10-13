<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Registration;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Laravel\Fortify\Fortify;
use Pushery\FortifyHardening\Support\AddressBudget;
use Pushery\FortifyHardening\Support\Settings;

/**
 * Registers an address without telling the caller whether it was free.
 *
 * A new address gets its account and the `Registered` event, as Fortify would give it. A taken
 * address gets no second account, and its holder is told that somebody tried. Two requests that race
 * for one new address end the same way: the one that loses is treated as a taken address. Nobody is
 * signed in, because a session for the new account is exactly what a taken address could not get,
 * and the difference would answer the question the rest of the flow keeps closed.
 *
 * Both outcomes cost the same password hash. Creating an account hashes its password and finding
 * one does not, so without that a free address would take a whole bcrypt hash longer to answer than
 * a taken one, about a quarter of a second at bcrypt's default cost of 12 rounds, and one request
 * would tell them apart.
 */
final readonly class Registrar
{
    public function __construct(
        private Hasher $hasher,
        private Dispatcher $events,
        private AddressBudget $budget,
        private AuthManager $auth,
        private Container $container,
        private Settings $settings,
    ) {}

    /**
     * Register an address whose account is created from a hashed password.
     *
     * The password is hashed once, before the address is looked up, and the hash is what $create
     * receives, so a free address and a taken one pay the same hash. Store it as it is: a model with
     * the `hashed` cast leaves an existing hash alone.
     *
     * @param  Closure(string): Authenticatable  $create  creates the account from the hashed password
     */
    public function register(string $address, string $password, Closure $create): void
    {
        $hashed = $this->hasher->make($password);

        $this->settle($address, static fn (): Authenticatable => $create($hashed));
    }

    /**
     * Register an address whose account is created by code that hashes the password itself, such
     * as Fortify's `CreatesNewUsers`. A taken address then pays one hash of the same password with
     * the same hasher, so both outcomes cost the same.
     *
     * @param  Closure(): Authenticatable  $create  creates the account
     */
    public function registerHashingInside(string $address, string $password, Closure $create): void
    {
        $this->settle($address, $create, fn (): string => $this->hasher->make($password));
    }

    /**
     * @param  Closure(): Authenticatable  $create
     * @param  (Closure(): string)|null  $equalize  the hash a taken address pays when $create pays its own
     */
    private function settle(string $address, Closure $create, ?Closure $equalize = null): void
    {
        $account = $this->find($address);

        if ($account instanceof Authenticatable) {
            if ($equalize instanceof Closure) {
                $equalize();
            }

            $this->notify($address, $account);

            return;
        }

        try {
            $created = $create();
        } catch (UniqueConstraintViolationException|AddressTurnedOutTaken) {
            // The account exists, the winner of the race created it. Routed by address rather than
            // looked up again: on PostgreSQL a constraint violation aborts the surrounding
            // transaction, and any further query on the connection would fail louder than the race.
            $this->notify($address, null);

            return;
        }

        $this->budget->spend($address);
        $this->events->dispatch(new Registered($created));
    }

    private function find(string $address): ?Authenticatable
    {
        return $this->auth
            ->createUserProvider($this->settings->userProvider())
            ?->retrieveByCredentials([Fortify::username() => $address]);
    }

    private function notify(string $address, ?Authenticatable $account): void
    {
        $this->budget->send($address, function () use ($address, $account): void {
            $notification = $this->container->make($this->settings->noticeNotification());

            if (! $notification instanceof Notification) {
                return;
            }

            if ($account instanceof Authenticatable && method_exists($account, 'notify')) {
                $account->notify($notification);

                return;
            }

            new AnonymousNotifiable()->route('mail', $address)->notify($notification);
        });
    }
}
