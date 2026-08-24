<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Data\Sandbox;

/**
 * What a sandbox should be provisioned with.
 */
final readonly class SandboxConfig
{
    /**
     * @param  array<string, string>  $environment
     * @param  array<int, string>  $allowedHosts  empty means deny all outbound network access
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $sessionId,
        public ?string $image = null,
        public ?string $workspacePath = null,
        public array $environment = [],
        public array $allowedHosts = [],
        public int $timeoutSeconds = 900,
        public array $options = [],
    ) {}
}
