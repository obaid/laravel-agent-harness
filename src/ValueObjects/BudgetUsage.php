<?php

declare(strict_types=1);

namespace Clutch\Laravel\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Accumulated consumption for a run, carried across attempts.
 *
 * @implements Arrayable<string, int|float>
 */
final readonly class BudgetUsage implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $steps = 0,
        public int $toolCalls = 0,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $reasoningTokens = 0,
        public int $cacheReadInputTokens = 0,
        public int $cacheWriteInputTokens = 0,
        public float $costUsd = 0.0,
        public int $elapsedSeconds = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $usage
     */
    public static function fromArray(array $usage): self
    {
        return new self(
            steps: (int) ($usage['steps'] ?? 0),
            toolCalls: (int) ($usage['tool_calls'] ?? 0),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            reasoningTokens: (int) ($usage['reasoning_tokens'] ?? 0),
            cacheReadInputTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cacheWriteInputTokens: (int) ($usage['cache_write_input_tokens'] ?? 0),
            costUsd: (float) ($usage['cost_usd'] ?? 0),
            elapsedSeconds: (int) ($usage['elapsed_seconds'] ?? 0),
        );
    }

    /**
     * Total billable tokens across prompt, completion and reasoning.
     */
    public function totalTokens(): int
    {
        return $this->promptTokens
            + $this->completionTokens
            + $this->reasoningTokens
            + $this->cacheReadInputTokens
            + $this->cacheWriteInputTokens;
    }

    public function add(self $other): self
    {
        return new self(
            steps: $this->steps + $other->steps,
            toolCalls: $this->toolCalls + $other->toolCalls,
            promptTokens: $this->promptTokens + $other->promptTokens,
            completionTokens: $this->completionTokens + $other->completionTokens,
            reasoningTokens: $this->reasoningTokens + $other->reasoningTokens,
            cacheReadInputTokens: $this->cacheReadInputTokens + $other->cacheReadInputTokens,
            cacheWriteInputTokens: $this->cacheWriteInputTokens + $other->cacheWriteInputTokens,
            costUsd: round($this->costUsd + $other->costUsd, 6),
            elapsedSeconds: max($this->elapsedSeconds, $other->elapsedSeconds),
        );
    }

    public function withSteps(int $steps): self
    {
        return new self(
            $steps, $this->toolCalls, $this->promptTokens, $this->completionTokens,
            $this->reasoningTokens, $this->cacheReadInputTokens, $this->cacheWriteInputTokens,
            $this->costUsd, $this->elapsedSeconds,
        );
    }

    public function withToolCalls(int $toolCalls): self
    {
        return new self(
            $this->steps, $toolCalls, $this->promptTokens, $this->completionTokens,
            $this->reasoningTokens, $this->cacheReadInputTokens, $this->cacheWriteInputTokens,
            $this->costUsd, $this->elapsedSeconds,
        );
    }

    public function withElapsedSeconds(int $seconds): self
    {
        return new self(
            $this->steps, $this->toolCalls, $this->promptTokens, $this->completionTokens,
            $this->reasoningTokens, $this->cacheReadInputTokens, $this->cacheWriteInputTokens,
            $this->costUsd, $seconds,
        );
    }

    public function withCostUsd(float $costUsd): self
    {
        return new self(
            $this->steps, $this->toolCalls, $this->promptTokens, $this->completionTokens,
            $this->reasoningTokens, $this->cacheReadInputTokens, $this->cacheWriteInputTokens,
            round($costUsd, 6), $this->elapsedSeconds,
        );
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'steps' => $this->steps,
            'tool_calls' => $this->toolCalls,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'total_tokens' => $this->totalTokens(),
            'cost_usd' => $this->costUsd,
            'elapsed_seconds' => $this->elapsedSeconds,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
