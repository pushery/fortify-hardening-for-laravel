<?php

declare(strict_types=1);

namespace Pushery\FortifyHardening\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Laravel\Fortify\LoginRateLimiter;
use Override;

/**
 * Fortify's sign-in limiter, counting under a key that names nobody.
 *
 * Fortify counts failed sign-ins under the lower-cased address and the client IP as they are, so every
 * failed attempt writes both into the cache key, and a successful one clears that key again. The key
 * then stands in Redis or the cache table, and an error tracker records it with every cache call it
 * traces. This limiter counts under the same pair, hashed with the application key: the count, the
 * limit and the wait are Fortify's, and the key no longer says who tried to sign in from where.
 */
final class HashedLoginRateLimiter extends LoginRateLimiter
{
    public function __construct(RateLimiter $limiter, private readonly Repository $config)
    {
        parent::__construct($limiter);
    }

    #[Override]
    protected function throttleKey(Request $request): string
    {
        $secret = $this->config->get('app.key');

        return 'fortify-hardening:login:'.hash_hmac('sha256', parent::throttleKey($request), is_string($secret) ? $secret : '');
    }
}
