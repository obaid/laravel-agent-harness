<?php

declare(strict_types=1);

namespace Clutch\Laravel\Data;

/**
 * The driver's live handle on a session.
 *
 * `state` is opaque to the harness; only the driver interprets it. The harness
 * persists it inside checkpoints so a later worker can restore the same handle.
 */
final readonly class DriverSession
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public string $sessionId,
        public string $driver,
        public array $state = [],
        public ?string $conversationId = null,
    ) {}

    /**
     * Return a copy carrying a new conversation identifier.
     */
    public function withConversationId(?string $conversationId): self
    {
        return new self($this->sessionId, $this->driver, $this->state, $conversationId);
    }

    /**
     * Return a copy carrying merged driver state.
     *
     * @param  array<string, mixed>  $state
     */
    public function withState(array $state): self
    {
        return new self($this->sessionId, $this->driver, [...$this->state, ...$state], $this->conversationId);
    }

    /**
     * Read a value from the opaque driver state.
     */
    public function state(string $key, mixed $default = null): mixed
    {
        return data_get($this->state, $key, $default);
    }
}
