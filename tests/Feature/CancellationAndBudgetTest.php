<?php

declare(strict_types=1);

use AgentHarness\Laravel\Enums\ApprovalStatus;
use AgentHarness\Laravel\Enums\FailureCategory;
use AgentHarness\Laravel\Enums\RunStatus;
use AgentHarness\Laravel\Facades\Harness;
use AgentHarness\Laravel\Models\Approval;
use AgentHarness\Laravel\Runtime\CancellationSignal;
use AgentHarness\Laravel\Runtime\HarnessResult;
use AgentHarness\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use AgentHarness\Laravel\ValueObjects\BudgetUsage;
use AgentHarness\Laravel\ValueObjects\RunBudget;

beforeEach(function (): void {
    $this->owner = $this->user();
    $this->harness = Harness::fake([HarnessResult::text('All done.')]);
});

it('cancels a queued run before it starts', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $run = Harness::coordinator()->createRun($session, 'A long analysis.');

    $run->cancel('The request is no longer needed.');

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Cancelled)
        ->and($run->failure_category)->toBe(FailureCategory::Cancelled)
        ->and($run->cancellation_reason)->toBe('The request is no longer needed.')
        ->and($run->finished_at)->not->toBeNull();

    // The session slot is released so new work can start.
    expect($session->refresh()->active_run_id)->toBeNull();
});

it('stops a paused run and cancels its outstanding approvals', function (): void {
    $this->harness->script([
        HarnessResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $paused = $session->prompt('Publish it.');

    expect($paused->isAwaitingApproval())->toBeTrue();

    $paused->run->cancel('No longer relevant.');

    expect($paused->run->refresh()->status)->toBe(RunStatus::Cancelled)
        ->and(Approval::query()->firstOrFail()->status)->toBe(ApprovalStatus::Cancelled);
});

it('records cancellation as a terminal event', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = Harness::coordinator()->createRun($session, 'Something.');

    $run->cancel('Changed my mind.');

    $terminal = $run->refresh()->events()->where('type', 'run.cancelled')->first();

    expect($terminal)->not->toBeNull()
        ->and($terminal->payload['reason'])->toBe('Changed my mind.');
});

it('prevents a driver from starting new work once cancellation is observed', function (): void {
    $signal = CancellationSignal::cancelled('stop');

    expect($signal->isCancelled())->toBeTrue()
        ->and($signal->reason())->toBe('stop');
});

it('re-reads durable cancellation state at each boundary', function (): void {
    $cancelled = false;

    $signal = new CancellationSignal(function () use (&$cancelled): bool {
        return $cancelled;
    }, refreshIntervalSeconds: 0);

    expect($signal->isCancelled())->toBeFalse();

    $cancelled = true;

    expect($signal->forceRefresh()->isCancelled())->toBeTrue();
});

it('stops a run that has already exhausted its budget before starting a new attempt', function (): void {
    $session = Harness::agent(ResearchAgent::class)
        ->for($this->owner)
        ->budget(new RunBudget(maxTokens: 100))
        ->create();

    $run = Harness::coordinator()->createRun($session, 'Expensive work.');

    // Prior attempts already spent the budget.
    $run->forceFill(['usage' => (new BudgetUsage(promptTokens: 120))->toArray()])->save();

    Harness::coordinator()->executeRun($run->id);

    $run->refresh();

    expect($run->status)->toBe(RunStatus::BudgetExceeded)
        ->and($run->failure_category)->toBe(FailureCategory::BudgetExceeded);

    $event = $run->events()->where('type', 'run.budget_exceeded')->firstOrFail();

    expect($event->payload['limit'])->toBe('max_tokens')
        ->and($event->payload['max'])->toBe(100);

    $this->harness->assertRunExceededBudget();
});

it('carries usage across attempts so a retry cannot re-spend the budget', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $first = $session->prompt('Do the work.');

    $failed = $first->run;
    $failed->forceFill([
        'status' => RunStatus::Failed,
        'usage' => (new BudgetUsage(steps: 3, promptTokens: 500))->toArray(),
    ])->save();

    $retry = $failed->retry();

    expect($retry->attempt)->toBe(2)
        ->and($retry->retry_of_run_id)->toBe($failed->id)
        ->and($retry->usage()->promptTokens)->toBe(500)
        ->and($retry->usage()->steps)->toBe(3);
});

it('resets the budget on request', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $run = $session->prompt('Do the work.')->run;
    $run->forceFill([
        'status' => RunStatus::Failed,
        'usage' => (new BudgetUsage(steps: 3, promptTokens: 500))->toArray(),
    ])->save();

    $retry = $run->retry(resetBudget: true);

    expect($retry->usage()->promptTokens)->toBe(0)
        ->and($retry->usage()->steps)->toBe(0);
});

it('never reopens a terminal run when retrying', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $original = $session->prompt('Do the work.')->run;

    expect($original->status)->toBe(RunStatus::Completed);

    $retry = $original->retry();

    expect($retry->id)->not->toBe($original->id)
        ->and($original->refresh()->status)->toBe(RunStatus::Completed)
        ->and($original->finished_at)->not->toBeNull();
});
