<?php

declare(strict_types=1);

use AgentHarness\Laravel\Enums\RunStatus;
use AgentHarness\Laravel\Enums\SessionStatus;

it('treats exactly the four end states as terminal', function (): void {
    $terminal = array_filter(RunStatus::cases(), fn (RunStatus $s): bool => $s->isTerminal());

    expect(array_map(fn (RunStatus $s): string => $s->value, array_values($terminal)))
        ->toBe(['completed', 'failed', 'cancelled', 'budget_exceeded']);
});

it('allows no transition out of a terminal run state', function (RunStatus $status): void {
    expect($status->allowedTransitions())->toBe([]);
})->with([
    RunStatus::Completed,
    RunStatus::Failed,
    RunStatus::Cancelled,
    RunStatus::BudgetExceeded,
]);

it('lets a run reach every terminal state from running', function (): void {
    foreach ([RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::BudgetExceeded] as $terminal) {
        expect(RunStatus::Running->canTransitionTo($terminal))->toBeTrue();
    }
});

it('routes a paused run back through the queue rather than straight to running', function (): void {
    expect(RunStatus::AwaitingApproval->canTransitionTo(RunStatus::Queued))->toBeTrue();
});

it('refuses to move a completed run back to running', function (): void {
    expect(RunStatus::Completed->canTransitionTo(RunStatus::Running))->toBeFalse();
});

it('pairs each terminal status with its terminal event', function (): void {
    expect(RunStatus::Completed->terminalEventType()?->value)->toBe('run.completed')
        ->and(RunStatus::Failed->terminalEventType()?->value)->toBe('run.failed')
        ->and(RunStatus::Cancelled->terminalEventType()?->value)->toBe('run.cancelled')
        ->and(RunStatus::BudgetExceeded->terminalEventType()?->value)->toBe('run.budget_exceeded')
        ->and(RunStatus::Running->terminalEventType())->toBeNull();
});

it('only accepts new work in a settled session', function (): void {
    expect(SessionStatus::Ready->acceptsNewRun())->toBeTrue()
        ->and(SessionStatus::Stopped->acceptsNewRun())->toBeTrue()
        ->and(SessionStatus::Running->acceptsNewRun())->toBeFalse()
        ->and(SessionStatus::AwaitingApproval->acceptsNewRun())->toBeFalse()
        ->and(SessionStatus::Destroyed->acceptsNewRun())->toBeFalse();
});

it('lets a session return to ready after a run finishes', function (): void {
    expect(SessionStatus::Running->canTransitionTo(SessionStatus::Ready))->toBeTrue()
        ->and(SessionStatus::AwaitingApproval->canTransitionTo(SessionStatus::Running))->toBeTrue();
});
