<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Contracts;

use AgentHarness\Laravel\Data\Sandbox\SandboxCheckpoint;
use AgentHarness\Laravel\Data\Sandbox\SandboxConfig;
use AgentHarness\Laravel\Data\Sandbox\SandboxSession;

/**
 * Filesystem and process isolation for runtimes that need it.
 *
 * The default driver runs host-resident Laravel AI agents and uses the null
 * provider. Cloud sandbox integrations belong in separate packages.
 */
interface SandboxProvider
{
    public function create(SandboxConfig $config): SandboxSession;

    public function restore(SandboxCheckpoint $checkpoint): SandboxSession;

    public function checkpoint(SandboxSession $session): SandboxCheckpoint;

    public function stop(SandboxSession $session): SandboxCheckpoint;

    public function destroy(SandboxSession $session): void;
}
