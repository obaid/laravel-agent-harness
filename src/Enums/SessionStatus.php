<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Enums;

enum SessionStatus: string
{
    case Creating = 'creating';
    case Ready = 'ready';
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Destroyed = 'destroyed';

    /**
     * Determine whether the session may begin a new run.
     */
    public function acceptsNewRun(): bool
    {
        return $this === self::Ready || $this === self::Stopped;
    }

    /**
     * Determine whether the session can no longer change.
     */
    public function isTerminal(): bool
    {
        return $this === self::Destroyed;
    }

    /**
     * Get the statuses that may follow this one.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Creating => [self::Ready, self::Failed],
            self::Ready => [self::Running, self::Stopping, self::Stopped, self::Failed, self::Destroyed],
            self::Running => [self::Ready, self::AwaitingApproval, self::Stopping, self::Failed],
            self::AwaitingApproval => [self::Running, self::Ready, self::Stopping, self::Failed],
            self::Stopping => [self::Stopped, self::Failed],
            self::Stopped => [self::Ready, self::Destroyed],
            self::Failed => [self::Destroyed],
            self::Destroyed => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), strict: true);
    }
}
