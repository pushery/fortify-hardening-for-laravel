<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Support;

use Closure;
use Illuminate\Cache\RateLimiter;
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
 * A mail is counted before it is sent, under a lock per address, so requests that arrive together
 * cannot all read an unspent budget and all send. The lock is taken in the store the count lives
 * in, Laravel's `cache.limiter`, and in the default store where that one cannot lock. A send that
 * throws gives its count back. The key is a hash of the address, so the cache holds no address.
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
        $count = $this->count($address, 1);

        if ($count === null) {
            return false;
        }

        if ($count > $this->settings->maxMailsPerHour()) {
            $this->count($address, -1);

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
     * Count a mail that goes out another way, such as the verification link of a new account.
     */
    public function spend(string $address): void
    {
        $this->count($address, 1);
    }

    /**
     * Raise the address's count by $amount and return the new count, or null when its lock could
     * not be had in time. A request that cannot count is refused rather than let through uncounted.
     */
    private function count(string $address, int $amount): ?int
    {
        $key = 'fortify-hardening:registration-mail:'.hash('sha256', $address);
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
