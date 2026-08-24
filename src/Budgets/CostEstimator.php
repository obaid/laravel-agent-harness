<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Budgets;

use AgentHarness\Laravel\ValueObjects\BudgetUsage;

/**
 * Turns token usage into an estimated dollar cost.
 *
 * Rates are configured per `provider:model`, expressed in USD per million
 * tokens. An unpriced model contributes nothing rather than guessing, so a
 * `maxCostUsd` budget over unpriced models simply never triggers — which is
 * visible in the usage record rather than silently wrong.
 */
class CostEstimator
{
    /**
     * @param  array<string, array{input?: float, output?: float, cache_read?: float, cache_write?: float}>  $rates
     */
    public function __construct(protected array $rates = []) {}

    /**
     * Estimate the cost of the given usage for a provider and model.
     */
    public function estimate(BudgetUsage $usage, ?string $provider, ?string $model): float
    {
        $rate = $this->rateFor($provider, $model);

        if ($rate === null) {
            return 0.0;
        }

        $perMillion = fn (int $tokens, float $price): float => ($tokens / 1_000_000) * $price;

        return round(
            $perMillion($usage->promptTokens, $rate['input'] ?? 0.0)
            + $perMillion($usage->completionTokens + $usage->reasoningTokens, $rate['output'] ?? 0.0)
            + $perMillion($usage->cacheReadInputTokens, $rate['cache_read'] ?? ($rate['input'] ?? 0.0))
            + $perMillion($usage->cacheWriteInputTokens, $rate['cache_write'] ?? ($rate['input'] ?? 0.0)),
            6,
        );
    }

    /**
     * Determine whether a model has configured pricing.
     */
    public function hasRateFor(?string $provider, ?string $model): bool
    {
        return $this->rateFor($provider, $model) !== null;
    }

    /**
     * Look up a rate, falling back from `provider:model` to a bare `model` key.
     *
     * @return array{input?: float, output?: float, cache_read?: float, cache_write?: float}|null
     */
    protected function rateFor(?string $provider, ?string $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return $this->rates["{$provider}:{$model}"]
            ?? $this->rates[$model]
            ?? null;
    }
}
