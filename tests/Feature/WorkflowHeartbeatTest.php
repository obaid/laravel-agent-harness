<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Jobs\ReapAbandonedRuns;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Tests\Fixtures\Workflows\HeartbeatWorkflow;
use Clutch\Laravel\Tests\Fixtures\Workflows\ParallelWorkflow;

/**
 * Saying "still here" while the work is long.
 *
 * `runs.heartbeat_at` was written once, when the run started, and never again.
 * The reaper reads a stale heartbeat as a worker that died, so any workflow
 * that spent longer inside its own steps than the staleness threshold was
 * killed while it was working — and the retry did exactly the same thing.
 */
beforeEach(function (): void {
    $this->owner = $this->user();
});

it('beats the heartbeat while it works, not only when it starts', function (): void {
    config()->set('clutch.workflows.concurrent_steps', false);

    // The step backdates the heartbeat by an hour from inside its own body.
    // Anything fresher on the row afterwards was written around the work.
    $result = HeartbeatWorkflow::start()->for($this->owner)->runNow([]);

    $run = Run::query()->findOrFail($result->run->id);

    expect($run->heartbeat_at)->not->toBeNull()
        ->and($run->heartbeat_at->greaterThan(now()->subMinute()))->toBeTrue();
});

it('keeps a working run out of the reaper by beating between steps', function (): void {
    config()->set('clutch.workflows.concurrent_steps', false);

    $result = ParallelWorkflow::start()->for($this->owner)->runNow(['id' => 7]);
    $run = Run::query()->findOrFail($result->run->id);

    // Put the run back to running with a start well beyond the threshold, but
    // a heartbeat as fresh as the steps would have left it.
    Run::query()->whereKey($run->id)->update([
        'status' => RunStatus::Running->value,
        'started_at' => now()->subHour(),
        'finished_at' => null,
        'heartbeat_at' => now(),
    ]);

    app()->call([new ReapAbandonedRuns(staleAfterSeconds: 300), 'handle']);

    expect(Run::query()->findOrFail($run->id)->status)->toBe(RunStatus::Running);
});

it('still reaps a run whose heartbeat really has stopped', function (): void {
    config()->set('clutch.workflows.concurrent_steps', false);

    $result = ParallelWorkflow::start()->for($this->owner)->runNow(['id' => 7]);
    $run = Run::query()->findOrFail($result->run->id);

    Run::query()->whereKey($run->id)->update([
        'status' => RunStatus::Running->value,
        'started_at' => now()->subHour(),
        'finished_at' => null,
        'heartbeat_at' => now()->subHour(),
    ]);

    app()->call([new ReapAbandonedRuns(staleAfterSeconds: 300, retry: false), 'handle']);

    expect(Run::query()->findOrFail($run->id)->status)->toBe(RunStatus::Failed);
});

it('will not call a worker dead before a single step could have returned', function (): void {
    // The reaper's threshold has to clear the longest a healthy worker can go
    // without speaking, which is one concurrent step. A threshold under it
    // reaps workers mid-flight, which is the bug this release exists for.
    expect((int) config('clutch.recovery.stale_after_seconds'))
        ->toBeGreaterThan((int) config('clutch.workflows.step_timeout'));
});
