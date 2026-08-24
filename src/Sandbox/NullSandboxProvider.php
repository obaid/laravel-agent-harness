<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Sandbox;

use AgentHarness\Laravel\Contracts\SandboxProvider;
use AgentHarness\Laravel\Data\Sandbox\SandboxCheckpoint;
use AgentHarness\Laravel\Data\Sandbox\SandboxConfig;
use AgentHarness\Laravel\Data\Sandbox\SandboxSession;

/**
 * The provider used by host-resident application agents.
 *
 * Laravel AI tools already execute in the Laravel host under normal
 * application authorization, so no isolation is provisioned.
 */
final class NullSandboxProvider implements SandboxProvider
{
    public function create(SandboxConfig $config): SandboxSession
    {
        return new SandboxSession($config->sessionId, 'null');
    }

    public function restore(SandboxCheckpoint $checkpoint): SandboxSession
    {
        return new SandboxSession((string) $checkpoint->sessionId, 'null');
    }

    public function checkpoint(SandboxSession $session): SandboxCheckpoint
    {
        return new SandboxCheckpoint('null', 1, [], $session->sessionId);
    }

    public function stop(SandboxSession $session): SandboxCheckpoint
    {
        return $this->checkpoint($session);
    }

    public function destroy(SandboxSession $session): void
    {
        //
    }
}
