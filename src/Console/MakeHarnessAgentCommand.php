<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Generates a Laravel AI agent already wired for durable harness sessions.
 *
 * The one thing `make:agent` leaves out is the `RemembersConversations` trait,
 * without which Laravel AI never persists the conversation — and durable
 * sessions and cross-process approvals both depend on it.
 */
#[AsCommand(name: 'make:harness-agent')]
class MakeHarnessAgentCommand extends GeneratorCommand
{
    protected $name = 'make:harness-agent';

    protected $description = 'Create a Laravel AI agent ready for durable harness sessions';

    protected $type = 'Agent';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/harness-agent.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Ai\Agents';
    }
}
