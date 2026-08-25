<?php

declare(strict_types=1);

namespace Clutch\Laravel\Data;

use Clutch\Laravel\ValueObjects\BudgetUsage;
use Clutch\Laravel\ValueObjects\NormalizedFailure;

/**
 * What a driver returns when it stops working on a turn.
 *
 * Exactly one outcome is true. Most are terminal, but `suspended` is not: it
 * means the driver stopped at a safe boundary with work still to do, and the
 * run should be re-queued to carry on.
 */
final readonly class TurnResult
{
    public const COMPLETED = 'completed';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const BUDGET_EXCEEDED = 'budget_exceeded';

    /**
     * The driver hit a step or time-slice boundary with work remaining.
     *
     * Unlike every other outcome this is not terminal. A queue worker that is
     * about to time out can hand the turn back mid-flight, and the coordinator
     * re-queues it to continue from the checkpoint.
     */
    public const SUSPENDED = 'suspended';

    /**
     * @param  array<int, PendingApproval>  $pendingApprovals
     * @param  array<string, mixed>|null  $structuredOutput
     */
    public function __construct(
        public string $outcome,
        public ?string $text = null,
        public ?array $structuredOutput = null,
        public array $pendingApprovals = [],
        public BudgetUsage $usage = new BudgetUsage,
        public ?NormalizedFailure $failure = null,
        public ?DriverSession $session = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     */
    public static function completed(
        ?string $text,
        ?array $structuredOutput = null,
        BudgetUsage $usage = new BudgetUsage,
        ?DriverSession $session = null,
    ): self {
        return new self(self::COMPLETED, $text, $structuredOutput, [], $usage, null, $session);
    }

    /**
     * @param  array<int, PendingApproval>  $pendingApprovals
     */
    public static function awaitingApproval(
        array $pendingApprovals,
        ?string $text = null,
        BudgetUsage $usage = new BudgetUsage,
        ?DriverSession $session = null,
    ): self {
        return new self(self::AWAITING_APPROVAL, $text, null, $pendingApprovals, $usage, null, $session);
    }

    public static function cancelled(
        ?string $text = null,
        BudgetUsage $usage = new BudgetUsage,
        ?DriverSession $session = null,
    ): self {
        return new self(self::CANCELLED, $text, null, [], $usage, null, $session);
    }

    /**
     * The driver stopped because a hard budget limit was reached.
     *
     * @param  array<string, mixed>  $exhaustion
     */
    public static function budgetExceeded(
        array $exhaustion,
        ?string $text = null,
        BudgetUsage $usage = new BudgetUsage,
        ?DriverSession $session = null,
    ): self {
        return new self(
            self::BUDGET_EXCEEDED, $text, null, [], $usage,
            new NormalizedFailure(
                \Clutch\Laravel\Enums\FailureCategory::BudgetExceeded,
                'The run stopped because its ['.($exhaustion['limit'] ?? 'budget').'] limit was reached.',
            ),
            $session,
        );
    }

    /**
     * The driver stopped at a safe boundary with work still to do.
     *
     * The session handle is required: it is what the next attempt resumes from.
     */
    public static function suspended(
        DriverSession $session,
        ?string $text = null,
        BudgetUsage $usage = new BudgetUsage,
        ?string $reason = null,
    ): self {
        return new self(
            self::SUSPENDED, $text, null, [], $usage,
            $reason === null ? null : new NormalizedFailure(
                \Clutch\Laravel\Enums\FailureCategory::Unknown, $reason,
            ),
            $session,
        );
    }

    public static function failed(
        NormalizedFailure $failure,
        BudgetUsage $usage = new BudgetUsage,
        ?DriverSession $session = null,
    ): self {
        return new self(self::FAILED, null, null, [], $usage, $failure, $session);
    }

    public function isCompleted(): bool
    {
        return $this->outcome === self::COMPLETED;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->outcome === self::AWAITING_APPROVAL;
    }

    public function isCancelled(): bool
    {
        return $this->outcome === self::CANCELLED;
    }

    public function isFailed(): bool
    {
        return $this->outcome === self::FAILED;
    }

    public function exceededBudget(): bool
    {
        return $this->outcome === self::BUDGET_EXCEEDED;
    }

    /**
     * Determine whether the driver stopped with work still to do.
     */
    public function isSuspended(): bool
    {
        return $this->outcome === self::SUSPENDED;
    }
}
