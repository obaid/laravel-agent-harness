<?php

declare(strict_types=1);

namespace Clutch\Laravel\Data;

use Clutch\Laravel\Enums\PermissionMode;

/**
 * The command handed to a driver when a session is first created.
 */
final readonly class StartSession
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function __construct(
        public string $sessionId,
        public ?string $agentClass,
        public ?string $runtimeName,
        public PermissionMode $permissionMode,
        public array $configuration = [],
        public ?object $participant = null,
        public ?string $workspaceId = null,
    ) {}

    /**
     * Get a configuration value.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->configuration, $key, $default);
    }
}
