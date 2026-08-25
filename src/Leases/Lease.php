<?php

declare(strict_types=1);

namespace Clutch\Laravel\Leases;

/**
 * A held claim on a session's execution slot.
 */
final class Lease
{
    public function __construct(
        public readonly string $key,
        public readonly string $owner,
        protected \Closure $renewer,
        protected \Closure $releaser,
    ) {}

    /**
     * Extend the lease. Returns false when the lease was lost.
     */
    public function renew(): bool
    {
        return (bool) ($this->renewer)();
    }

    /**
     * Give up the lease.
     */
    public function release(): void
    {
        ($this->releaser)();
    }
}
