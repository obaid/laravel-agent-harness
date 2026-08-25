<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\FailureCategory;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Exceptions\LeaseUnavailable;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Jobs\ExecuteAgentRun;
use Clutch\Laravel\Jobs\ReapAbandonedRuns;
use Clutch\Laravel\Leases\LeaseManager;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;

beforeEach(function (): void {
    $this->owner = $this->user();
    $this->clutch = Clutch::fake([ClutchResult::text('Done.')]);
    $this->session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
});

it('lets only one worker hold a session lease at a time', function (): void {
    $leases = app(LeaseManager::class);

    $first = $leases->acquire($this->session->id);

    expect($first)->not->toBeNull()
        ->and($leases->acquire($this->session->id))->toBeNull();

    $first->release();

    expect($leases->acquire($this->session->id))->not->toBeNull();
});

it('exits safely rather than executing while another worker holds the lease', function (): void {
    $leases = app(LeaseManager::class);
    $run = Clutch::coordinator()->createRun($this->session, 'Do it.');

    $held = $leases->acquire($this->session->id);

    // A second delivery arrives while the first worker is still going.
    $this->app->call([new ExecuteAgentRun($run->id, $run->version), 'handle']);

    expect($run->refresh()->status)->toBe(RunStatus::Created)
        ->and($this->clutch->driver()->prompts)->toBeEmpty();

    $held->release();
});

it('surfaces lease contention as a typed exception to direct callers', function (): void {
    $leases = app(LeaseManager::class);
    $run = Clutch::coordinator()->createRun($this->session, 'Do it.');

    $held = $leases->acquire($this->session->id);

    expect(fn () => Clutch::coordinator()->executeRun($run->id))
        ->toThrow(LeaseUnavailable::class);

    $held->release();
});

it('releases the lease even when a run fails', function (): void {
    $this->clutch->script([ClutchResult::failure('The provider exploded.')]);

    $leases = app(LeaseManager::class);

    $result = $this->session->prompt('Do it.');

    expect($result->isFailed())->toBeTrue()
        ->and($leases->isHeld($this->session->id))->toBeFalse();
});

it('normalizes a driver failure into a safe terminal state', function (): void {
    $this->clutch->script([ClutchResult::failure('Provider said: sk-live-leak in body')]);

    $result = $this->session->prompt('Do it.');

    expect($result->run->status)->toBe(RunStatus::Failed)
        ->and($result->run->failure_category)->toBe(FailureCategory::DriverError)
        ->and($result->run->finished_at)->not->toBeNull()
        ->and($this->session->refresh()->active_run_id)->toBeNull();
});

it('recovers a run whose worker disappeared', function (): void {
    $run = Clutch::coordinator()->createRun($this->session, 'Do it.');

    // Simulate a worker that died mid-run: still running, heartbeat long stale.
    $run->forceFill([
        'status' => RunStatus::Running,
        'started_at' => now()->subHour(),
        'heartbeat_at' => now()->subHour(),
    ])->save();

    $this->app->call([new ReapAbandonedRuns(staleAfterSeconds: 300, retry: true), 'handle']);

    $original = Run::query()->findOrFail($run->id);

    expect($original->status)->toBe(RunStatus::Failed)
        ->and($original->failure_category)->toBe(FailureCategory::WorkerLost);

    // A fresh attempt was queued rather than the terminal record being reopened.
    $retry = Run::query()->where('retry_of_run_id', $run->id)->firstOrFail();

    expect($retry->attempt)->toBe(2);
});

it('leaves a healthy run alone', function (): void {
    $run = Clutch::coordinator()->createRun($this->session, 'Do it.');

    $run->forceFill([
        'status' => RunStatus::Running,
        'started_at' => now()->subHour(),
        'heartbeat_at' => now(),
    ])->save();

    $this->app->call([new ReapAbandonedRuns(staleAfterSeconds: 300), 'handle']);

    expect($run->refresh()->status)->toBe(RunStatus::Running);
});

it('does not reap a run whose worker still holds the lease', function (): void {
    $leases = app(LeaseManager::class);
    $run = Clutch::coordinator()->createRun($this->session, 'Do it.');

    $run->forceFill([
        'status' => RunStatus::Running,
        'started_at' => now()->subHour(),
        'heartbeat_at' => now()->subHour(),
    ])->save();

    $held = $leases->acquire($this->session->id);

    $this->app->call([new ReapAbandonedRuns(staleAfterSeconds: 300), 'handle']);

    // A stale heartbeat with a live lease means a slow worker, not a dead one.
    expect($run->refresh()->status)->toBe(RunStatus::Running);

    $held->release();
});

it('records a normalized failure when the job itself dies', function (): void {
    $run = Clutch::coordinator()->createRun($this->session, 'Do it.');

    (new ExecuteAgentRun($run->id, $run->version))->failed(new RuntimeException('OOM'));

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failure_category)->toBe(FailureCategory::WorkerLost)
        ->and($run->failure_message)->toBe('The worker processing this run stopped unexpectedly.')
        // The raw exception message is not exposed to the user.
        ->and($run->failure_message)->not->toContain('OOM')
        ->and($this->session->refresh()->active_run_id)->toBeNull();
});

it('classifies a rate limit as retryable and a validation error as not', function (): void {
    $rateLimited = \Clutch\Laravel\ValueObjects\NormalizedFailure::fromThrowable(
        new RuntimeException('429 Too Many Requests'),
    );

    $invalid = \Clutch\Laravel\ValueObjects\NormalizedFailure::fromThrowable(
        new InvalidArgumentException('The prompt was empty.'),
    );

    expect($rateLimited->category)->toBe(FailureCategory::RateLimited)
        ->and($rateLimited->retryable)->toBeTrue()
        ->and($invalid->category)->toBe(FailureCategory::ValidationError)
        ->and($invalid->retryable)->toBeFalse();
});

it('keeps provider detail out of the message shown to a user', function (): void {
    $failure = \Clutch\Laravel\ValueObjects\NormalizedFailure::fromThrowable(
        new RuntimeException('429 rate limit; key sk-live-abc123 quota exceeded'),
    );

    expect($failure->message)->not->toContain('sk-live-abc123')
        ->and($failure->message)->toBe('The model provider rate limited this run. It may be retried shortly.')
        // The class is kept for operators; the body is not.
        ->and($failure->exceptionClass)->toBe(RuntimeException::class);
});
