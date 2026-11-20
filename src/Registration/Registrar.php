<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Registration;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Timebox;
use Laravel\Fortify\Fortify;
use Pushery\FortifyHardening\Support\AddressBudget;
use Pushery\FortifyHardening\Support\Settings;
use SensitiveParameter;

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
 *
 * A free address costs more than its hash all the same: the creator queries and inserts, and may
 * create more than the account, and the application's `Registered` listeners run for it alone. So
 * both outcomes also run inside one timebox, held to the minimum the guard holds a failed sign-in to,
 * `auth.timebox_duration`, and neither returns early. Work that runs past that minimum shows again.
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
     * the `hashed` cast leaves an existing hash alone. The password is marked as sensitive, so the trace
     * of a failure in the lookup or in $create holds a placeholder in its place, even on a server that
     * keeps the arguments of a trace.
     *
     * @param  Closure(string): Authenticatable  $create  creates the account from the hashed password
     */
    public function register(string $address, #[SensitiveParameter] string $password, Closure $create): void
    {
        $hashed = $this->hasher->make($password);

        $this->settle($address, static fn (): Authenticatable => $create($hashed));
    }

    /**
     * Register an address whose account is created by code that hashes the password itself, such
     * as Fortify's `CreatesNewUsers`. A taken address then pays one hash of the same password with
     * the same hasher, so both outcomes cost the same. The password is marked as sensitive, as above.
     *
     * @param  Closure(): Authenticatable  $create  creates the account
     */
    public function registerHashingInside(string $address, #[SensitiveParameter] string $password, Closure $create): void
    {
        $this->settle($address, $create, fn (): string => $this->hasher->make($password));
    }

    /**
     * Settle a registration inside the timebox, which neither outcome leaves early.
     *
     * @param  Closure(): Authenticatable  $create
     * @param  (Closure(): string)|null  $equalize  the hash a taken address pays when $create pays its own
     */
    private function settle(string $address, Closure $create, ?Closure $equalize = null): void
    {
        new Timebox()->call(function () use ($address, $create, $equalize): void {
            $this->createOrNotify($address, $create, $equalize);
        }, $this->settings->guardTimeboxMicroseconds());
    }

    /**
     * Create the account of a free address, or tell the holder of a taken one.
     *
     * @param  Closure(): Authenticatable  $create
     * @param  (Closure(): string)|null  $equalize  the hash a taken address pays when $create pays its own
     */
    private function createOrNotify(string $address, Closure $create, ?Closure $equalize): void
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
            $created = $this->create($create); // savepoint-ok: create() runs the insert in a savepoint wherever a transaction is open
        } catch (UniqueConstraintViolationException|AddressTurnedOutTaken) {
            // The account exists, the winner of the race created it, and its holder is told by address. The failed
            // insert took nothing else with it: create() ran it in a savepoint wherever a transaction was open, so the
            // statements that follow, the mail budget's among them, run on a connection that still answers.
            $this->notify($address, null);

            return;
        }

        // Over the budget the new account's verification mail stays back, as the notice to a holder does. The
        // event still goes out, because the application's other listeners are owed it, and the mail is stopped
        // on its way.
        if (! $this->budget->spend($address)) {
            $this->budget->holdVerification($created);
        }

        $this->events->dispatch(new Registered($created));
    }

    /**
     * Run the creator, inside a savepoint wherever a transaction is open on the connection the accounts live on.
     *
     * The creator of a request that loses the race for an address fails at the unique index. PostgreSQL then aborts
     * the transaction around the failed statement, and every later statement in it fails as well: the notice, the mail
     * budget on the database cache store, and whatever the application does after the registration. A savepoint takes
     * the failed insert back alone, as Laravel's own createOrFirst() does. Outside a transaction the insert is a
     * statement of its own, and nothing else is there to lose.
     *
     * @param  Closure(): Authenticatable  $create
     */
    private function create(Closure $create): Authenticatable
    {
        $connection = $this->accountConnection();

        return $connection->transactionLevel() > 0
            ? $connection->transaction(static fn (): Authenticatable => $create())
            : $create();
    }

    /** The connection the accounts live on: the one the model of an Eloquent user provider queries, or the default one. */
    private function accountConnection(): ConnectionInterface
    {
        $provider = $this->auth->createUserProvider($this->settings->userProvider());

        return $provider instanceof EloquentUserProvider
            ? $provider->createModel()->getConnection()
            : $this->container->make(ConnectionResolverInterface::class)->connection();
    }

    /**
     * The account of an address, found where Fortify's password reset finds it: under `fortify.email`, which
     * is also the field people sign in with unless they sign in with a username of their own.
     */
    private function find(string $address): ?Authenticatable
    {
        return $this->auth
            ->createUserProvider($this->settings->userProvider())
            ?->retrieveByCredentials([Fortify::email() => $address]);
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
