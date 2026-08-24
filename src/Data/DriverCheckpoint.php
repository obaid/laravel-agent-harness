<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Data;

/**
 * A versioned, opaque snapshot of driver state.
 *
 * The coordinator persists and restores checkpoints without interpreting the
 * payload. Secrets must never be placed inside one.
 */
final readonly class DriverCheckpoint
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $driver,
        public int $schemaVersion,
        public array $payload,
        public string $reason = 'boundary',
        public bool $portable = false,
        public ?string $sessionId = null,
        public ?string $runId = null,
    ) {}

    /**
     * Compute a stable digest of the payload for integrity checks.
     */
    public function digest(): string
    {
        return hash('sha256', (string) json_encode($this->payload));
    }

    /**
     * Return a copy that belongs to the given session and run.
     */
    public function for(string $sessionId, ?string $runId): self
    {
        return new self(
            $this->driver, $this->schemaVersion, $this->payload,
            $this->reason, $this->portable, $sessionId, $runId,
        );
    }

    /**
     * Return a copy with a different recorded reason.
     */
    public function because(string $reason): self
    {
        return new self(
            $this->driver, $this->schemaVersion, $this->payload,
            $reason, $this->portable, $this->sessionId, $this->runId,
        );
    }

    /**
     * Read a value from the checkpoint payload.
     */
    public function payload(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, $key, $default);
    }
}
