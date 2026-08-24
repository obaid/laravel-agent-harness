<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Leases;

use AgentHarness\Laravel\Exceptions\LeaseUnavailable;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * Guarantees one active coordinator per session.
 *
 * Redis is preferred for heartbeat leases. When the cache store cannot provide
 * atomic locks, the database `version` and `active_run_id` columns remain the
 * final correctness check, so a lost or mis-expired lease can never produce two
 * committed writers.
 */
class LeaseManager
{
    public function __construct(
        protected CacheFactory $cache,
        protected ?string $store = null,
        protected int $ttlSeconds = 60,
        protected int $heartbeatSeconds = 15,
    ) {}

    /**
     * The cache key a session's lease lives under.
     */
    public function keyFor(string $sessionId): string
    {
        return "agent-harness:session:{$sessionId}";
    }

    /**
     * Run a callback while holding the session lease, releasing it in every path.
     *
     * @template TReturn
     *
     * @param  Closure(Lease): TReturn  $callback
     * @return TReturn
     *
     * @throws LeaseUnavailable
     */
    public function withLease(string $sessionId, Closure $callback): mixed
    {
        $lease = $this->acquire($sessionId);

        if (! $lease instanceof Lease) {
            throw LeaseUnavailable::forSession($sessionId);
        }

        try {
            return $callback($lease);
        } finally {
            $lease->release();
        }
    }

    /**
     * Attempt to take the lease for a session, returning null when another
     * worker already holds it.
     */
    public function acquire(string $sessionId, ?string $owner = null): ?Lease
    {
        $key = $this->keyFor($sessionId);
        $owner ??= (string) Str::uuid();
        $store = $this->cache->store($this->store);

        $inner = $store->getStore();

        if (! $inner instanceof LockProvider) {
            // Refusing is deliberate. A "best effort" read-then-write lease is
            // racy, and quietly running two coordinators for one session is
            // exactly the failure this package exists to prevent. Every cache
            // store Laravel ships except "null" supports atomic locks.
            throw new LeaseUnavailable(
                'The cache store backing agent-harness leases does not support atomic locks, so the harness '.
                'cannot guarantee one worker per session. Point [agent-harness.leases.store] at a lock-capable '.
                'store such as redis, memcached, database, file, or array.'
            );
        }

        $lock = $inner->lock($key, $this->ttlSeconds, $owner);

        if (! $lock->get()) {
            return null;
        }

        return new Lease(
            key: $key,
            owner: $owner,
            renewer: function () use ($inner, $key, $owner): bool {
                // Re-taking an owned lock resets its TTL.
                return $inner->lock($key, $this->ttlSeconds, $owner)->get();
            },
            releaser: function () use ($lock): void {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The lock already expired; the database remains authoritative.
                }
            },
        );
    }

    /**
     * Determine whether a session's lease is currently held by anyone.
     *
     * Implemented by attempting to take the lease rather than reading the key:
     * a cache lock does not live under a key `has()` can see, so a direct read
     * would report "free" for a lease that is very much held.
     *
     * This is advisory. Anything that acts on the answer should hold the lease
     * itself — see `withLease()` — rather than checking and then acting.
     */
    public function isHeld(string $sessionId): bool
    {
        $lease = $this->acquire($sessionId);

        if (! $lease instanceof Lease) {
            return true;
        }

        $lease->release();

        return false;
    }

    /**
     * How often a worker should renew its lease.
     */
    public function heartbeatSeconds(): int
    {
        return $this->heartbeatSeconds;
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }
}
