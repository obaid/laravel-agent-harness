<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Jobs\ReapAbandonedRuns;
use Clutch\Laravel\Runtime\RunCoordinator;
use Clutch\Laravel\Tests\Fixtures\Workflows\InstrumentedWorkflow;
use Clutch\Laravel\Workflows\Workflow;

/**
 * Interrupt a workflow everywhere it can be interrupted, and check the only
 * thing that actually matters: nothing happened twice.
 *
 * Written this way because every workflow bug so far was found by someone
 * interrupting a run by hand and noticing the counter had moved. Doing it at
 * every boundary, systematically, is the version of that which does not
 * depend on anyone remembering to try.
 */
beforeEach(function (): void {
    InstrumentedWorkflow::reset();
    $this->owner = $this->user();
});

afterEach(function (): void {
    InstrumentedWorkflow::reset();
});

// Pausing at each boundary ----------------------------------------------------

it('does no step twice, whichever boundary it paused at', function (string $pauseAfter): void {
    $run = InstrumentedWorkflow::start()->for($this->owner)
        ->dispatch(['pause_after' => $pauseAfter]);

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingApproval);

    Workflow::resume($run->id, ['approved' => true]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(InstrumentedWorkflow::counts())->toBe(['alpha' => 1, 'beta' => 1, 'gamma' => 1]);
})->with(InstrumentedWorkflow::STEPS);

it('does no step twice when resumed more than once', function (): void {
    $run = InstrumentedWorkflow::start()->for($this->owner)->dispatch(['pause_after' => 'alpha']);

    Workflow::resume($run->id, ['approved' => true]);

    // Resuming an already-finished run must be a no-op, not a second run.
    Workflow::resume($run->id, ['approved' => true]);
    Workflow::resume($run->id, ['approved' => true]);

    expect(InstrumentedWorkflow::counts())->toBe(['alpha' => 1, 'beta' => 1, 'gamma' => 1]);
});

// Failing at each step --------------------------------------------------------

it('keeps every earlier step when it fails partway', function (string $failAt): void {
    InstrumentedWorkflow::$failAt = $failAt;

    $failed = InstrumentedWorkflow::start()->for($this->owner)->runNow([]);

    expect($failed->run->status)->toBe(RunStatus::Failed)
        ->and($failed->run->failure_message)->toContain($failAt);

    // Only the steps up to and including the failure ran.
    $expected = [];
    foreach (InstrumentedWorkflow::STEPS as $step) {
        $expected[$step] = InstrumentedWorkflow::STEPS === []
            ? 0
            : (array_search($step, InstrumentedWorkflow::STEPS, true)
                <= array_search($failAt, InstrumentedWorkflow::STEPS, true) ? 1 : 0);
    }

    expect(InstrumentedWorkflow::counts())->toBe($expected);
})->with(InstrumentedWorkflow::STEPS);

it('retries a failure without repeating what already worked', function (string $failAt): void {
    InstrumentedWorkflow::$failAt = $failAt;

    $failed = InstrumentedWorkflow::start()->for($this->owner)->runNow([]);
    expect($failed->run->status)->toBe(RunStatus::Failed);

    // The transient cause clears, as it would in practice.
    InstrumentedWorkflow::$failAt = null;

    $coordinator = app(RunCoordinator::class);
    $retried = $coordinator->retryRun($failed->run);
    $coordinator->executeRun($retried->id);

    expect($retried->refresh()->status)->toBe(RunStatus::Completed);

    $counts = InstrumentedWorkflow::counts();
    $failedAt = array_search($failAt, InstrumentedWorkflow::STEPS, true);

    foreach (InstrumentedWorkflow::STEPS as $step) {
        $position = array_search($step, InstrumentedWorkflow::STEPS, true);

        // Three cases, and the middle one is the whole point: a step that
        // threw produced no result, so it is the only one that runs twice.
        [$expected, $why] = match (true) {
            $position < $failedAt => [1, 'finished before the failure, so the retry must skip it'],
            $position === $failedAt => [2, 'threw without producing a result, so the retry must redo it'],
            default => [1, 'never got the chance to run until the retry'],
        };

        expect($counts[$step])->toBe($expected, "[{$step}] {$why}.");
    }
})->with(InstrumentedWorkflow::STEPS);

// Losing the worker at each boundary ------------------------------------------

it('does no step twice when the worker vanishes at a pause', function (string $pauseAfter): void {
    $run = InstrumentedWorkflow::start()->for($this->owner)->dispatch(['pause_after' => $pauseAfter]);

    $before = InstrumentedWorkflow::counts();

    $run->refresh()->forceFill([
        'status' => RunStatus::Running,
        'heartbeat_at' => now()->subHour(),
        'started_at' => now()->subHour(),
    ])->save();

    dispatch_sync(new ReapAbandonedRuns(staleAfterSeconds: 60, retry: true));

    // The recovery re-entered the body and did not redo a thing.
    expect(InstrumentedWorkflow::counts())->toBe($before);
})->with(InstrumentedWorkflow::STEPS);

// Cancelling -------------------------------------------------------------------

it('does not undo or repeat finished steps when cancelled', function (): void {
    $run = InstrumentedWorkflow::start()->for($this->owner)->dispatch(['pause_after' => 'beta']);

    $before = InstrumentedWorkflow::counts();
    expect($before['alpha'])->toBe(1)->and($before['beta'])->toBe(1);

    app(RunCoordinator::class)->requestCancellation($run->refresh(), 'enough');

    expect(InstrumentedWorkflow::counts())->toBe($before)
        ->and($run->refresh()->status)->toBeIn([RunStatus::Cancelling, RunStatus::Cancelled]);
});

// The shape of the record ------------------------------------------------------

it('records each step exactly once as really having run', function (): void {
    $run = InstrumentedWorkflow::start()->for($this->owner)->dispatch(['pause_after' => 'beta']);
    Workflow::resume($run->id, ['approved' => true]);

    $ran = $run->refresh()->events()
        ->where('type', 'step.completed')
        ->get()
        ->reject(fn ($e): bool => (bool) ($e->payload['replayed'] ?? false))
        ->pluck('payload.step');

    // The history has to agree with the counters, or the demo's own display
    // would be telling people something the package did not do.
    expect($ran->all())->toBe(InstrumentedWorkflow::STEPS)
        ->and($ran->duplicates())->toBeEmpty();
});
