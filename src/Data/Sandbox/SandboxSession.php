<?php

declare(strict_types=1);

namespace Clutch\Laravel\Data\Sandbox;

/**
 * A provider's live handle on a provisioned sandbox.
 */
final readonly class SandboxSession
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public string $sessionId,
        public string $provider,
        public ?string $workspacePath = null,
        public array $state = [],
    ) {}
}
