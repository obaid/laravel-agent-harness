<?php

declare(strict_types=1);

namespace Clutch\Laravel\Data\Sandbox;

/**
 * A restorable snapshot of a sandbox.
 */
final readonly class SandboxCheckpoint
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public int $schemaVersion,
        public array $payload,
        public ?string $sessionId = null,
    ) {}
}
