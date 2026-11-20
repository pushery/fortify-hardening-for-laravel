<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;

/**
 * Deletes the package's expired keys from a database cache store.
 *
 * A database store deletes the row of an expired key only when that key is read again, and the package writes keys that
 * nobody reads again: the note of a completed password reset under its session, the count of the mail budget under an
 * inbox that registers once, and the count of failed sign-ins under an address and IP that never try again. On a
 * database store their rows stay for good. Scheduled once a day, this deletes them, a thousand rows to a statement:
 *
 *     Schedule::call(PruneExpiredCacheKeys::class)->daily();
 *
 * It prunes the stores the package keeps its keys in, the default store and the store Laravel's rate limiter counts in,
 * `cache.limiter`, each of them once, and does nothing where neither is a database store. Only the row of a key that has
 * expired goes, and the store already treats such a row as missing.
 */
final readonly class PruneExpiredCacheKeys
{
    /** The most rows one statement deletes, so a large table is pruned in short statements. */
    private const int CHUNK = 1000;

    /** What every cache key of the package starts with. */
    private const string KEYS = 'fortify-hardening:';

    public function __construct(
        private Repository $config,
        private ConnectionResolverInterface $connections,
    ) {}

    /** Delete every expired key of the package, and return how many rows went. */
    public function __invoke(): int
    {
        $now = Carbon::now()->getTimestamp();
        $deleted = 0;

        foreach ($this->stores() as $store) {
            $connection = $this->connections->connection($store['connection']);

            do {
                $chunk = $connection->table($store['table'])
                    ->where('key', 'like', $store['pattern'])
                    ->where('expiration', '<=', $now)
                    ->limit(self::CHUNK)
                    ->delete();

                $deleted += $chunk;
            } while ($chunk === self::CHUNK);
        }

        return $deleted;
    }

    /**
     * The database stores the package keeps its keys in, each once: the default store, and the store Laravel's rate
     * limiter counts in, which is the default one where `cache.limiter` names none.
     *
     * @return list<array{table: string, connection: ?string, pattern: string}>
     */
    private function stores(): array
    {
        $stores = [];

        foreach ([$this->config->get('cache.default'), $this->config->get('cache.limiter')] as $name) {
            $store = $this->databaseStore($name);

            if ($store === null) {
                continue;
            }

            $stores[($store['connection'] ?? '').'|'.$store['table'].'|'.$store['pattern']] = $store;
        }

        return array_values($stores);
    }

    /**
     * What pruning a store takes, its table, its connection and the package's keys in it as a LIKE pattern, or null for a
     * store that is no database store.
     *
     * A character of the store's prefix that LIKE reads as more than itself on one of the engines, `%`, `\` and `[`,
     * stands as `_`, any one character, so the pattern means the same on every engine without an escape clause. It can
     * then match a key beside the package's, and so can a LIKE that ignores case, as SQLite's does and MySQL's under its
     * default collation. Such a key goes only once it has expired, and the store already treats it as missing then.
     *
     * @param  mixed  $configured  the store as the configuration names it, which a host may write as any type
     * @return array{table: string, connection: ?string, pattern: string}|null
     */
    private function databaseStore(mixed $configured): ?array
    {
        $name = is_string($configured) && $configured !== '' ? $configured : $this->config->get('cache.default');
        $stores = $this->config->get('cache.stores');
        $store = is_array($stores) && is_string($name) ? ($stores[$name] ?? null) : null;

        if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
            return null;
        }

        $table = $store['table'] ?? null;
        $connection = $store['connection'] ?? null;
        // As Laravel builds the store: its own prefix, or the one every store shares.
        $prefix = $store['prefix'] ?? $this->config->get('cache.prefix');

        if (! is_string($table) || $table === '') {
            return null;
        }

        return [
            'table' => $table,
            'connection' => is_string($connection) && $connection !== '' ? $connection : null,
            'pattern' => str_replace(['%', '\\', '['], '_', is_string($prefix) ? $prefix : '').self::KEYS.'%',
        ];
    }
}
