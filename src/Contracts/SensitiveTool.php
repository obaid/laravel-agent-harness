<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Contracts;

use AgentHarness\Laravel\Enums\ToolSensitivity;

/**
 * A tool that classifies its own risk so the policy engine does not have to guess.
 *
 * Tools that do not implement this are treated as sensitive.
 */
interface SensitiveTool
{
    public function sensitivity(): ToolSensitivity;
}
