<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class AvailabilityCacheService
{
    private const string KEY = 'slots:availability';
    private const string LOCK_KEY = 'slots:availability:lock';

    /** Last known good payload, served to readers that lose the lock race. */
    private const string STALE_KEY = 'slots:availability:stale';

    private const int TTL_MIN_SECONDS = 5;
    private const int TTL_MAX_SECONDS = 15;

    /** How long the recomputing worker may hold the lock. */
    private const int LOCK_TTL_SECONDS = 10;

    /** How long a reader waits for the winner on a cold cache. */
    private const int LOCK_WAIT_SECONDS = 3;

    private const int STALE_TTL_SECONDS = 300;

    /**
     * Return the cached payload, recomputing via `$resolver` on a miss.
     *
     * Single-flight: on a miss exactly one worker runs the resolver while the
     * others serve the stale copy instead of piling onto the database. Only a
     * genuinely cold cache makes readers wait.
     *
     * @param  callable(): array  $resolver
     */
    public function remember(callable $resolver): array
    {
        if (($cached = Cache::get(self::KEY)) !== null) {
            return $cached;
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        if ($lock->get()) {
            return $this->resolveHoldingLock($lock, $resolver);
        }

        // Another worker is recomputing. Stale data beats a queued query.
        if (($stale = Cache::get(self::STALE_KEY)) !== null) {
            return $stale;
        }

        // Cold cache with no stale copy: wait for the winner to publish.
        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // The winner is wedged or slow; answer from the source directly.
            return $resolver();
        }

        // The wait handed us the lock, and we keep it: waiting is not proof
        // that anything got published. The winner may have failed, or a write
        // may have invalidated the entry in between, and then this worker has
        // to resolve after all — which unlocked would let every waiter resolve
        // at once, recreating the stampede this class exists to absorb.
        return $this->resolveHoldingLock($lock, $resolver);
    }

    /**
     * Resolve while owning `$lock`, releasing it on the way out.
     *
     * Both callers arrive here meaning the same thing — "the lock is mine now"
     * — whether they took it outright or waited for it. Sharing the body keeps
     * the two paths from drifting into different guarantees.
     *
     * @param  callable(): array  $resolver
     */
    private function resolveHoldingLock(Lock $lock, callable $resolver): array
    {
        try {
            // Someone may have published while we were acquiring the lock.
            if (($cached = Cache::get(self::KEY)) !== null) {
                return $cached;
            }

            return $this->publish($resolver());
        } finally {
            $lock->release();
        }
    }

    /**
     * Drop the cached payload, stale copy included: after a write the stale
     * copy is known-wrong and must not be served.
     */
    public function invalidate(): void
    {
        Cache::forget(self::KEY);
        Cache::forget(self::STALE_KEY);
    }

    private function publish(array $payload): array
    {
        // A random TTL keeps entries cached together from expiring together
        // and re-creating the stampede this cache is meant to absorb.
        Cache::put(self::KEY, $payload, random_int(self::TTL_MIN_SECONDS, self::TTL_MAX_SECONDS));
        Cache::put(self::STALE_KEY, $payload, self::STALE_TTL_SECONDS);

        return $payload;
    }
}
