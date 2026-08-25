<?php

declare(strict_types=1);

namespace Clutch\Laravel\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The hard limits applied to a run.
 *
 * A null limit means "no limit from this layer". Budgets are layered:
 * configuration defaults, then session budget, then run budget. The most
 * restrictive non-null value at each level wins.
 *
 * @implements Arrayable<string, int|float|null>
 */
final readonly class RunBudget implements Arrayable, JsonSerializable
{
    public function __construct(
        public ?int $maxSteps = null,
        public ?int $maxToolCalls = null,
        public ?int $maxTokens = null,
        public ?float $maxCostUsd = null,
        public ?int $maxDurationSeconds = null,
    ) {}

    /**
     * Create a budget with no limits at all.
     */
    public static function unlimited(): self
    {
        return new self;
    }

    /**
     * Create a budget from an array, ignoring unknown keys.
     *
     * @param  array<string, mixed>  $budget
     */
    public static function fromArray(array $budget): self
    {
        return new self(
            maxSteps: self::intOrNull($budget['max_steps'] ?? $budget['maxSteps'] ?? null),
            maxToolCalls: self::intOrNull($budget['max_tool_calls'] ?? $budget['maxToolCalls'] ?? null),
            maxTokens: self::intOrNull($budget['max_tokens'] ?? $budget['maxTokens'] ?? null),
            maxCostUsd: self::floatOrNull($budget['max_cost_usd'] ?? $budget['maxCostUsd'] ?? null),
            maxDurationSeconds: self::intOrNull($budget['max_duration_seconds'] ?? $budget['maxDurationSeconds'] ?? null),
        );
    }

    /**
     * Merge another budget over this one, keeping the most restrictive limit for each field.
     *
     * A null on either side never loosens an existing limit.
     */
    public function mergeRestrictive(?self $other): self
    {
        if (! $other instanceof self) {
            return $this;
        }

        return new self(
            maxSteps: self::lowest($this->maxSteps, $other->maxSteps),
            maxToolCalls: self::lowest($this->maxToolCalls, $other->maxToolCalls),
            maxTokens: self::lowest($this->maxTokens, $other->maxTokens),
            maxCostUsd: self::lowest($this->maxCostUsd, $other->maxCostUsd),
            maxDurationSeconds: self::lowest($this->maxDurationSeconds, $other->maxDurationSeconds),
        );
    }

    /**
     * Determine whether every limit is unset.
     */
    public function isUnlimited(): bool
    {
        return $this->maxSteps === null
            && $this->maxToolCalls === null
            && $this->maxTokens === null
            && $this->maxCostUsd === null
            && $this->maxDurationSeconds === null;
    }

    /**
     * @return array<string, int|float|null>
     */
    public function toArray(): array
    {
        return [
            'max_steps' => $this->maxSteps,
            'max_tool_calls' => $this->maxToolCalls,
            'max_tokens' => $this->maxTokens,
            'max_cost_usd' => $this->maxCostUsd,
            'max_duration_seconds' => $this->maxDurationSeconds,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    private static function lowest(int|float|null $a, int|float|null $b): int|float|null
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => min($a, $b),
        };
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
