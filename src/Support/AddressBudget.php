<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Support;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

/**
 * How many mails the registration form may send to one address within an hour.
 *
 * The form answers the same for a taken address and a free one, and each answer mails the address:
 * a verification link to a new account, a notice to the holder of an existing one. A throttle on the
 * form counts the client, so a sender who changes client addresses could reach one inbox as often as
 * they liked. This budget counts the address instead. A mail over the budget is not sent, and the
 * answer to the form stays the same.
 *
 * Both outcomes spend from it, the verification mail of a new account included. If only the notice
 * counted, the second of two quick requests would tell the outcomes apart by its time: held back for
 * an address that was taken, sent for one the first request had only just registered.
 *
 * Over the budget the verification mail stays back as the notice does. Laravel sends it from a
 * listener of `Registered`, an event the application's other listeners are owed, so the event still
 * goes out and the mail is stopped on its way: the registrar notes the new account here, and the
 * package's listener on `NotificationSending` stops the account's next verification mail. The note is
 * kept in the store the count lives in, for the hour of the budget, so a verification mail sent from
 * a queue worker is held back there as well. The first verification mail takes the note, so a link
 * the person asks for again later goes out.
 *
 * A mail is counted before it is sent, under a lock per address, so requests that arrive together
 * cannot all read an unspent budget and all send. The lock is taken in the store the count lives
 * in, Laravel's `cache.limiter`, and in the default store where that one cannot lock. A send that
 * throws gives its count back.
 *
 * The count is kept per inbox rather than per spelling, under the key {@see Mailbox::of()} gives the
 * address: case, surrounding whitespace and a `+tag` in the local part reach the same inbox at most
 * providers, and so do the dots of a Gmail address, under `gmail.com` or `googlemail.com`. Each
 * spelling registers as a new address, so a budget per spelling would let one inbox receive a mail
 * for every variant. The key is an HMAC of that inbox under the application key, so the cache holds
 * no address, and a guessed address cannot be hashed and looked for among the keys an error tracker
 * or a store's monitor records.
 */
final readonly class AddressBudget
{
    private const int WINDOW_SECONDS = 3600;

    private const int LOCK_SECONDS = 5;

    private const int WAIT_SECONDS = 1;

    public function __construct(
        private RateLimiter $limiter,
        private Factory $caches,
        private Settings $settings,
    ) {}

    /**
     * Run $send when the address has budget left, and say whether it ran.
     *
     * @param  Closure(): mixed  $send
     *
     * @throws Throwable whatever $send throws, after its count is given back
     */
    public function send(string $address, Closure $send): bool
    {
        if (! $this->spend($address)) {
            return false;
        }

        try {
            $send();
        } catch (Throwable $throwable) {
            $this->count($address, -1);

            throw $throwable;
        }

        return true;
    }

    /**
     * Count a mail that goes out another way, such as the verification link of a new account, and say
     * whether it fits the budget. A mail that does not, or one whose address could not be counted, is
     * not counted, and holding it back is the caller's part.
     */
    public function spend(string $address): bool
    {
        $count = $this->count($address, 1);

        if ($count === null) {
            return false;
        }

        if ($count > $this->settings->maxMailsPerHour()) {
            $this->count($address, -1);

            return false;
        }

        return true;
    }

    /**
     * Hold back the next verification mail of an account the registrar created over the budget.
     */
    public function holdVerification(Authenticatable $account): void
    {
        $this->caches->store($this->settings->limiterStore())->put($this->verificationKey($account), true, self::WINDOW_SECONDS);
    }

    /**
     * Whether the verification mail of an account is held back. Answering takes the note, so only the
     * first verification mail after the registration is held.
     */
    public function takesVerification(Authenticatable $account): bool
    {
        return $this->caches->store($this->settings->limiterStore())->pull($this->verificationKey($account)) === true;
    }

    /**
     * The key of the note that holds an account's verification mail back: an HMAC of its class and its
     * identifier under the application key, which a queue worker computes again from the account it
     * restores.
     */
    private function verificationKey(Authenticatable $account): string
    {
        $identity = $account::class.'|'.json_encode($account->getAuthIdentifier(), JSON_THROW_ON_ERROR);

        return 'fortify-hardening:held-verification:'.hash_hmac('sha256', $identity, $this->settings->secret());
    }

    /**
     * Raise the address's count by $amount and return the new count, or null when its lock could
     * not be had in time. A request that cannot count is refused rather than let through uncounted.
     */
    private function count(string $address, int $amount): ?int
    {
        $key = 'fortify-hardening:registration-mail:'.hash_hmac('sha256', Mailbox::of($address), $this->settings->secret());
        $store = $this->lockStore();

        if (! $store instanceof LockProvider) {
            return $this->increment($key, $amount);
        }

        try {
            $count = $store->lock($key.':lock', self::LOCK_SECONDS)
                ->block(self::WAIT_SECONDS, fn (): int => $this->increment($key, $amount));
        } catch (LockTimeoutException) {
            return null;
        }

        return is_int($count) ? $count : null;
    }

    /**
     * The store that locks an address: the one its count lives in, Laravel's `cache.limiter`, or the
     * default store where that one cannot lock. Null when neither can.
     */
    private function lockStore(): ?LockProvider
    {
        foreach ([$this->settings->limiterStore(), null] as $name) {
            $store = $this->caches->store($name)->getStore();

            if ($store instanceof LockProvider) {
                return $store;
            }
        }

        return null;
    }

    private function increment(string $key, int $amount): int
    {
        $count = $this->limiter->increment($key, self::WINDOW_SECONDS, $amount);

        // A give-back that lands after its window expired finds no counter, and the increment then
        // writes a fresh one at minus one, which would let the next window send one mail more than
        // its budget. Below zero there is nothing to give back.
        if ($count < 0) {
            $this->limiter->clear($key);

            return 0;
        }

        return $count;
    }
}
