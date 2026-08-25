<?php

declare(strict_types=1);

use Clutch\Laravel\Drivers\LaravelAi\LaravelAiDriver;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Testing\DriverContractTests;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\Tests\Fixtures\Workflows\ContractWorkflow;
use Clutch\Laravel\Workflows\WorkflowDriver;

/**
 * Every driver the package ships, run through the same contract.
 *
 * The suite existed from the start but only the fake was ever put through it,
 * which is the wrong way round: the fake is the one driver whose behaviour is
 * already obvious. These are the two that do real work.
 */

/**
 * Give a driver that reads its own records something to read.
 */
function contractRecords(string $driver, array $configuration = []): array
{
    $session = Session::query()->create([
        'id' => 'ses_contract',
        'driver' => $driver,
        'status' => \Clutch\Laravel\Enums\SessionStatus::Ready,
        'permission_mode' => \Clutch\Laravel\Enums\PermissionMode::ApproveSensitive,
        'configuration' => $configuration,
    ]);

    $run = Run::query()->create([
        'id' => 'run_contract',
        'session_id' => $session->id,
        'attempt' => 1,
        'status' => RunStatus::Running,
        'input_type' => 'workflow',
        'input' => ['prompt' => 'contract', 'payload' => []],
        'last_event_sequence' => 0,
    ]);

    return [$session, $run];
}

it('holds the driver contract for the workflow driver', function (): void {
    contractRecords('workflow', ['workflow' => ContractWorkflow::class]);

    $result = DriverContractTests::for(
        app(WorkflowDriver::class),
        configuration: ['workflow' => ContractWorkflow::class],
    )->run();

    expect($result['passed'])
        ->toContain('declares truthful capabilities')
        ->toContain('starts and stops a session')
        ->toContain('processes a new turn')
        ->toContain('round-trips a checkpoint')
        ->toContain('keeps secrets out of checkpoints')
        ->toContain('returns a consistent terminal result');
});

it('holds the driver contract for the Laravel AI driver', function (): void {
    ResearchAgent::fake(array_fill(0, 30, 'Contract answer.'));

    contractRecords('laravel-ai');

    $result = DriverContractTests::for(
        app(LaravelAiDriver::class),
        agentClass: ResearchAgent::class,
    )->run();

    expect($result['passed'])
        ->toContain('declares truthful capabilities')
        ->toContain('starts and stops a session')
        ->toContain('processes a new turn')
        ->toContain('round-trips a checkpoint')
        ->toContain('keeps secrets out of checkpoints')
        ->toContain('returns a consistent terminal result');
});

it('registers every shipped driver under a resolvable name', function (): void {
    // The shipped config is the one users get. A name in it that does not
    // resolve is a broken install, and has been twice.
    $registry = Clutch::drivers();

    foreach (array_keys((array) config('clutch.drivers')) as $name) {
        expect($registry->driver($name))
            ->toBeInstanceOf(\Clutch\Laravel\Contracts\ClutchDriver::class, "Driver [{$name}] did not resolve.");
    }
});

it('gives every shipped driver a name that matches how it is registered', function (): void {
    foreach (['laravel-ai', 'workflow'] as $name) {
        expect(Clutch::drivers()->driver($name)->name())->toBe($name);
    }
});
