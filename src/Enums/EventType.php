<?php

declare(strict_types=1);

namespace Clutch\Laravel\Enums;

enum EventType: string
{
    case RunCreated = 'run.created';
    case RunQueued = 'run.queued';
    case RunStarted = 'run.started';
    case TextDelta = 'text.delta';
    case ReasoningDelta = 'reasoning.delta';
    case StepStarted = 'step.started';
    case StepCompleted = 'step.completed';
    case ToolCallRequested = 'tool.call.requested';
    case ToolCallCompleted = 'tool.call.completed';
    case ToolCallFailed = 'tool.call.failed';
    case ApprovalRequested = 'approval.requested';
    case ApprovalResolved = 'approval.resolved';
    case ArtifactCreated = 'artifact.created';
    case UsageUpdated = 'usage.updated';
    case CheckpointCreated = 'checkpoint.created';
    case RunAwaitingApproval = 'run.awaiting_approval';
    case RunCompleted = 'run.completed';
    case RunFailed = 'run.failed';
    case RunCancelled = 'run.cancelled';
    case RunBudgetExceeded = 'run.budget_exceeded';

    /** An un-normalized driver event, recorded only after redaction. */
    case DriverEvent = 'driver.event';

    /**
     * Determine whether this event closes the run's stream.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::RunCompleted, self::RunFailed, self::RunCancelled, self::RunBudgetExceeded => true,
            default => false,
        };
    }

    /**
     * Determine whether this event is a high-frequency delta.
     *
     * Deltas are fully reconstructable from the terminal run output, so
     * configuration may exclude them from persistence.
     */
    public function isDelta(): bool
    {
        return $this === self::TextDelta || $this === self::ReasoningDelta;
    }
}
