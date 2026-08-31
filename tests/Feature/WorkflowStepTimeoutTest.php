<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Tests\Fixtures\Workflows\ParallelWorkflow;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Concurrency;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * How long a concurrent step is allowed to take, and what happens when it
 * takes longer.
 *
 * Laravel's process driver applies no timeout unless it is given one, which
 * leaves Symfony's default of 60 seconds. Nobody chose that number and no
 * model-priced step can meet it, so a fan-out of agent calls was being killed
 * silently mid-flight.
 */
beforeEach(function (): void {
    $this->owner = $this->user();
    config()->set('clutch.workflows.concurrent_steps', true);
});

function timedOut(): ProcessTimedOutException
{
    $process = new Process(['true']);

    return new ProcessTimedOutException(
        new SymfonyProcessTimedOutException($process, SymfonyProcessTimedOutException::TYPE_GENERAL),
        Mockery::mock(ProcessResult::class),
    );
}

it('gives every concurrent step the configured timeout', function (): void {
    config()->set('clutch.workflows.step_timeout', 420);

    Concurrency::shouldReceive('run')
        ->once()
        ->withArgs(fn (array $tasks, ?int $timeout): bool => $timeout === 420)
        ->andReturn(['account' => 'account:7', 'usage' => 42]);

    $result = ParallelWorkflow::start()->for($this->owner)->runNow(['id' => 7]);

    expect($result->run->status)->toBe(RunStatus::Completed);
});

it('passes no timeout when the limit is removed', function (): void {
    config()->set('clutch.workflows.step_timeout', null);

    Concurrency::shouldReceive('run')
        ->once()
        ->withArgs(fn (array $tasks, ?int $timeout): bool => $timeout === null)
        ->andReturn(['account' => 'account:7', 'usage' => 42]);

    ParallelWorkflow::start()->for($this->owner)->runNow(['id' => 7]);
});

it('fails the run when a step outruns its timeout rather than re-running the wave in process', function (): void {
    // The bug this covers: the fallback below answered a timeout by running
    // every task again sequentially, which takes strictly longer than the
    // concurrent attempt that just failed — long enough to blow the worker's
    // own timeout and have it killed still holding the run.
    Concurrency::shouldReceive('run')->once()->andThrow(timedOut());

    $result = ParallelWorkflow::start()->for($this->owner)->runNow(['id' => 7]);

    expect($result->run->status)->toBe(RunStatus::Failed);
});

it('still falls back to running in process when the driver cannot serialise a step', function (): void {
    // A closure that captured something unserialisable is a different failure
    // with a different answer: the work itself is fine, so it is run here.
    Concurrency::shouldReceive('run')->once()->andThrow(new RuntimeException('Serialization of "Closure" is not allowed'));

    $result = ParallelWorkflow::start()->for($this->owner)->runNow(['id' => 7]);

    expect($result->run->status)->toBe(RunStatus::Completed)
        ->and($result->run->structured_output)->toBe([
            'account' => 'account:7',
            'usage' => 42,
        ]);
});
