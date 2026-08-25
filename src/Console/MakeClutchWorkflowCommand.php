<?php

declare(strict_types=1);

namespace Clutch\Laravel\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Generates a workflow: a finite job whose control flow is yours.
 *
 * The generated body already uses `step()`, because that is the one habit
 * that makes a workflow resumable rather than merely restartable.
 */
#[AsCommand(name: 'make:clutch-workflow')]
class MakeClutchWorkflowCommand extends GeneratorCommand
{
    protected $name = 'make:clutch-workflow';

    protected $description = 'Create a Clutch workflow: a durable, resumable job that can call agents';

    protected $type = 'Workflow';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/clutch-workflow.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Ai\Workflows';
    }
}
