<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Enums;

enum RunStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Cancelling = 'cancelling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case BudgetExceeded = 'budget_exceeded';

    /**
     * Determine whether the run has reached an immutable end state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled, self::BudgetExceeded => true,
            default => false,
        };
    }

    /**
     * Determine whether the run currently occupies its session's active run slot.
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Determine whether the run is parked waiting on something outside the worker.
     */
    public function isPaused(): bool
    {
        return $this === self::AwaitingApproval;
    }

    /**
     * Get the statuses that may follow this one.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // BudgetExceeded is reachable before a run starts: a budget the
            // earlier attempts already spent means this one can never do work.
            self::Created => [
                self::Queued, self::Running, self::Cancelling,
                self::Cancelled, self::Failed, self::BudgetExceeded,
            ],
            self::Queued => [
                self::Running, self::Cancelling, self::Cancelled,
                self::Failed, self::BudgetExceeded,
            ],
            self::Running => [
                self::Completed, self::AwaitingApproval, self::Cancelling,
                self::Cancelled, self::Failed, self::BudgetExceeded,
            ],
            self::AwaitingApproval => [self::Queued, self::Running, self::Cancelling, self::Cancelled, self::Failed],
            self::Cancelling => [self::Cancelled, self::Completed, self::Failed],
            self::Completed, self::Failed, self::Cancelled, self::BudgetExceeded => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), strict: true);
    }

    /**
     * Get the terminal event type that accompanies this status.
     */
    public function terminalEventType(): ?EventType
    {
        return match ($this) {
            self::Completed => EventType::RunCompleted,
            self::Failed => EventType::RunFailed,
            self::Cancelled => EventType::RunCancelled,
            self::BudgetExceeded => EventType::RunBudgetExceeded,
            default => null,
        };
    }
}
