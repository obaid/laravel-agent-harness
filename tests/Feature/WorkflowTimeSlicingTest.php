<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Jobs\ContinueAgentRun;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\RunCoordinator;
use Clutch\Laravel\Tests\Fixtures\Workflows\ParallelWorkflow;
use Clutch\Laravel\Tests\Fixtures\Workflows\SlowMarchWorkflow;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    SlowMarchWorkflow::reset();

    $this->owner = $this->user();
});

it('completes a workflow longer than one slice as a chain of suspensions', function (): void {
    // Three steps, one per slice: the sync queue carries each continuation,
    // so the run arrives completed with every step having run exactly once.
    $run = SlowMarchWorkflow::start()
        ->for($this->owner)
        ->sliceAfterSteps(1)
        ->dispatch(['label' => 'march'])
        ->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(SlowMarchWorkflow::$executions)->toBe(['one' => 1, 'two' => 1, 'three' => 1])
        ->and($run->structured_output)->toMatchArray([
            'one' => 'march-1',
            'two' => 'march-2',
            'three' => 'march-3',
        ]);

    // Two boundaries for three steps, each recorded in the run's history.
    expect($run->events()->get()->filter(
        fn ($event): bool => ($event->payload['driver_type'] ?? null) === 'workflow.sliced',
    ))->toHaveCount(2);
});

it('parks a sliced run as suspended and queues its own continuation', function (): void {
    $coordinator = app(RunCoordinator::class);

    // Held from the start, so the run is observed mid-chain rather than
    // being carried to completion by the sync queue.
    Queue::fake();

    $run = SlowMarchWorkflow::start()
        ->for($this->owner)
        ->sliceAfterSteps(1)
        ->dispatch(['label' => 'march']);

    $run = Run::query()->findOrFail($run->id);
    $coordinator->executeRun($run->id);
    $run->refresh();

    expect($run->status)->toBe(RunStatus::Suspended)
        ->and($run->status->isTerminal())->toBeFalse()
        ->and(SlowMarchWorkflow::$executions)->toBe(['one' => 1])
        // The work is unfinished, so the session slot stays taken.
        ->and($run->session->active_run_id)->toBe($run->id);

    Queue::assertPushed(ContinueAgentRun::class);

    // The next slice picks up at the first missing step — nothing re-runs.
    $coordinator->continueRun($run->id);

    expect(SlowMarchWorkflow::$executions)->toBe(['one' => 1, 'two' => 1]);
});

it('never slices before making progress, however tight the budget', function (): void {
    // A zero-ish wall-clock budget is spent before the first step even
    // starts. Slicing there would suspend forever without advancing; the
    // guard always lets one unit through per slice.
    $run = SlowMarchWorkflow::start()
        ->for($this->owner)
        ->sliceAfterSeconds(1)
        ->dispatch(['label' => 'march'])
        ->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(SlowMarchWorkflow::$executions)->toBe(['one' => 1, 'two' => 1, 'three' => 1]);
});

it('slices a concurrent fan-out at wave boundaries, keeping finished waves', function (): void {
    config()->set('clutch.workflows.concurrent_steps', false);
    config()->set('clutch.limits.max_steps_per_slice', 2);

    // ParallelWorkflow fans out through steps(); with a two-step slice the
    // fan-out suspends between waves and still lands every branch exactly
    // once across the chain of jobs.
    $run = ParallelWorkflow::start()
        ->for($this->owner)
        ->dispatch(['names' => ['ada', 'grace', 'edsger']])
        ->refresh();

    expect($run->status)->toBe(RunStatus::Completed);
});

it('runs unsliced when no limit is configured, exactly as before', function (): void {
    $run = SlowMarchWorkflow::start()
        ->for($this->owner)
        ->dispatch(['label' => 'march'])
        ->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->events()->get()->filter(
            fn ($event): bool => ($event->payload['driver_type'] ?? null) === 'workflow.sliced',
        ))->toHaveCount(0);
});
