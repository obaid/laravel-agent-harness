<?php

declare(strict_types=1);

namespace Clutch\Laravel\Compaction;

use Clutch\Laravel\ValueObjects\BudgetUsage;

/**
 * Decides when a session's context has grown enough to be summarized.
 *
 * A long-lived session accumulates conversation until every turn pays for the
 * whole history. Compaction trades the middle of that history for a summary,
 * keeping the earliest turns (which usually hold the task) and the most recent
 * (which hold the state).
 *
 * The threshold is expressed against the token budget rather than a raw count,
 * so it scales with whatever model the session is using.
 */
class CompactionPolicy
{
    public function __construct(
        /** Fraction of the token budget at which compaction is worthwhile. */
        protected float $triggerAtFraction = 0.7,
        /** Messages at the start of the conversation to leave untouched. */
        protected int $keepFirst = 2,
        /** Messages at the end to leave untouched. */
        protected int $keepRecent = 8,
        /** Sentences the summary may use. */
        protected int $summarySentences = 6,
        protected bool $enabled = false,
    ) {}

    /**
     * Determine whether a session should be compacted before its next turn.
     */
    public function shouldCompact(BudgetUsage $usage, ?int $maxTokens, int $messageCount): bool
    {
        if (! $this->enabled || $maxTokens === null || $maxTokens <= 0) {
            return false;
        }

        // Compacting a short conversation costs a model call and saves nothing.
        if ($messageCount <= $this->keepFirst + $this->keepRecent) {
            return false;
        }

        return $usage->totalTokens() >= (int) ($maxTokens * $this->triggerAtFraction);
    }

    /**
     * Split messages into the part to summarize and the parts to keep.
     *
     * @template TMessage
     *
     * @param  array<int, TMessage>  $messages
     * @return array{head: array<int, TMessage>, middle: array<int, TMessage>, tail: array<int, TMessage>}
     */
    public function partition(array $messages): array
    {
        $messages = array_values($messages);
        $count = count($messages);

        if ($count <= $this->keepFirst + $this->keepRecent) {
            return ['head' => $messages, 'middle' => [], 'tail' => []];
        }

        return [
            'head' => array_slice($messages, 0, $this->keepFirst),
            'middle' => array_slice($messages, $this->keepFirst, $count - $this->keepFirst - $this->keepRecent),
            'tail' => array_slice($messages, -$this->keepRecent),
        ];
    }

    public function summarySentences(): int
    {
        return $this->summarySentences;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function triggerAtFraction(): float
    {
        return $this->triggerAtFraction;
    }
}
