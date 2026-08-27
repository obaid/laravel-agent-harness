<?php

declare(strict_types=1);

namespace Clutch\Laravel\Budgets;

use Clutch\Laravel\ValueObjects\BudgetUsage;

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

        // Providers resolve a requested model to a dated snapshot and report
        // that in the response — ask for `gpt-5.1`, get `gpt-5.1-2025-11-13`
        // back. The driver prices what the response says, so without this
        // fallback every dated response missed the configured rate and
        // estimated $0.00, and a `maxCostUsd` budget over it never triggered.
        // Found dogfooding against FWD, whose own ledger already stripped the
        // suffix the same way.
        $bare = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model);

        return $this->rates["{$provider}:{$model}"]
            ?? $this->rates[$model]
            ?? $this->rates["{$provider}:{$bare}"]
            ?? $this->rates[$bare]
            ?? null;
    }
}
