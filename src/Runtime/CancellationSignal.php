<?php

declare(strict_types=1);

namespace Clutch\Laravel\Runtime;

use Closure;

/**
 * The cooperative cancellation channel handed to a driver.
 *
 * Cancellation is cooperative: the harness prevents any *new* model step or
 * tool from starting once the signal is observed. A tool already executing may
 * finish unless that tool itself supports interruption.
 */
class CancellationSignal
{
    protected bool $cancelled = false;

    protected ?string $reason = null;

    /**
     * @param  (Closure(): bool)|null  $refresh  re-reads durable cancellation state
     */
    public function __construct(
        protected ?Closure $refresh = null,
        protected int $refreshIntervalSeconds = 2,
    ) {}

    /**
     * A signal that is never cancelled, for tests and synchronous paths.
     */
    public static function never(): self
    {
        return new self;
    }

    /**
     * A signal that is already cancelled.
     */
    public static function cancelled(?string $reason = null): self
    {
        return tap(new self)->cancel($reason);
    }

    protected ?int $lastRefreshedAt = null;

    /**
     * Determine whether cancellation has been requested.
     *
     * Drivers must consult this at every safe boundary, and must not begin new
     * work once it returns true.
     *
     * @phpstan-impure the durable cancellation state is re-read as time passes
     */
    public function isCancelled(): bool
    {
        if ($this->cancelled) {
            return true;
        }

        if ($this->refresh instanceof Closure && $this->shouldRefresh()) {
            $this->lastRefreshedAt = time();

            if (($this->refresh)() === true) {
                $this->cancelled = true;
            }
        }

        return $this->cancelled;
    }

    /**
     * Mark this signal as cancelled locally.
     */
    public function cancel(?string $reason = null): static
    {
        $this->cancelled = true;
        $this->reason = $reason;

        return $this;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Throw away the cached refresh window so the next check re-reads state.
     */
    public function forceRefresh(): static
    {
        $this->lastRefreshedAt = null;

        return $this;
    }

    protected function shouldRefresh(): bool
    {
        return $this->lastRefreshedAt === null
            || (time() - $this->lastRefreshedAt) >= $this->refreshIntervalSeconds;
    }
}
