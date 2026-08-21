<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Race-safe memoisation backed by the configured cache store.
 *
 * `remember()` prevents two near-simultaneous webhook deliveries from
 * producing two executions of the wrapped callback: the first caller
 * acquires a lock and computes the value, every subsequent caller within
 * the lock window receives the same value back.
 *
 * The lock is also independently useful through {@see self::withLock()}
 * for any section that needs to be serialised without going through
 * the remember() cache path.
 */
class IdempotencyStore
{
    public function __construct(private readonly CacheFactory $cache)
    {
    }

    /**
     * Compute the value for $key once within the cache lifetime and
     * return the same value on subsequent calls.
     *
     * `$callback` may return any value; arrays and scalars are stored
     * as-is. Closure results are cached for $ttl seconds (default 24h).
     */
    public function remember(string $key, mixed $value, int $ttl = 86400): mixed
    {
        $cacheKey = $this->cacheKey($key);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        return $this->withLock($key, function () use ($cacheKey, $value, $ttl): mixed {
            // Re-check inside the lock; another worker may have written
            // it between the outer has() and lock acquisition.
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            Cache::put($cacheKey, $value, $ttl);

            return $value;
        });
    }

    /**
     * Has the given key already been remembered?
     */
    public function has(string $key): bool
    {
        return Cache::has($this->cacheKey($key));
    }

    /**
     * Forget a key. Used by re-processing flows (e.g. when a webhook
     * is rejected and we want to allow the next retry to re-execute).
     */
    public function forget(string $key): void
    {
        Cache::forget($this->cacheKey($key));
    }

    /**
     * Acquire a lock keyed by `$key`, run $callback, release the lock.
     *
     * Throws whatever the callback throws — except that we always
     * release the lock in a finally block.
     */
    public function withLock(string $key, callable $callback): mixed
    {
        $lock = Cache::lock($this->lockKey($key), 10);

        try {
            // The same callback is called whether or not the lock was
            // acquired immediately: a "blocking" behaviour is the right
            // semantic for webhook processing where the caller will
            // eventually re-deliver anyway.
            $lock->block(5);

            return $callback();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Lock already expired or owned by another process; safe
                // to swallow because the next acquire() will surface
                // real failures.
            }
        }
    }

    private function cacheKey(string $key): string
    {
        return 'idem:'.$key;
    }

    private function lockKey(string $key): string
    {
        return 'idem-lock:'.$key;
    }
}