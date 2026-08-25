<?php

declare(strict_types=1);

namespace Clutch\Laravel\Data;

use Clutch\Laravel\ValueObjects\TurnLimits;

/**
 * The resolved decisions that wake a paused turn.
 */
final readonly class Continuation
{
    /**
     * @param  array<int, ApprovalDecision>  $decisions
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $runId,
        public array $decisions = [],
        /** How much work this slice may do before handing the turn back. */
        public TurnLimits $limits = new TurnLimits,
        public bool $streaming = false,
        public array $options = [],
    ) {}

    /**
     * Determine whether every decision was a rejection.
     */
    public function isFullRejection(): bool
    {
        return $this->decisions !== [] && ! $this->hasApproval();
    }

    /**
     * Determine whether any tool call was approved.
     */
    public function hasApproval(): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->approved) {
                return true;
            }
        }

        return false;
    }
}
