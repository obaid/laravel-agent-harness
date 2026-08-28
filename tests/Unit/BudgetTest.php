<?php

declare(strict_types=1);

use Clutch\Laravel\Budgets\BudgetManager;
use Clutch\Laravel\Budgets\CostEstimator;
use Clutch\Laravel\ValueObjects\BudgetUsage;
use Clutch\Laravel\ValueObjects\RunBudget;

it('keeps the more restrictive limit when merging budgets', function (): void {
    $merged = (new RunBudget(maxSteps: 50, maxTokens: 100_000))
        ->mergeRestrictive(new RunBudget(maxSteps: 10, maxCostUsd: 5.0));

    expect($merged->maxSteps)->toBe(10)
        ->and($merged->maxTokens)->toBe(100_000)
        ->and($merged->maxCostUsd)->toBe(5.0);
});

it('never loosens an existing limit with a null', function (): void {
    $merged = (new RunBudget(maxSteps: 10))->mergeRestrictive(new RunBudget(maxSteps: null));

    expect($merged->maxSteps)->toBe(10);
});

it('reports the first exhausted limit', function (): void {
    $manager = new BudgetManager;
    $budget = new RunBudget(maxSteps: 5, maxToolCalls: 10, maxTokens: 1_000);

    expect($manager->exhaustedLimit($budget, new BudgetUsage(steps: 4)))->toBeNull()
        ->and($manager->exhaustedLimit($budget, new BudgetUsage(steps: 5)))->toBe('max_steps')
        ->and($manager->exhaustedLimit($budget, new BudgetUsage(toolCalls: 10)))->toBe('max_tool_calls')
        ->and($manager->exhaustedLimit($budget, new BudgetUsage(promptTokens: 1_000)))->toBe('max_tokens');
});

it('counts every token category toward the token limit', function (): void {
    $usage = new BudgetUsage(
        promptTokens: 100,
        completionTokens: 50,
        reasoningTokens: 25,
        cacheReadInputTokens: 10,
        cacheWriteInputTokens: 5,
    );

    expect($usage->totalTokens())->toBe(190);
});

it('accumulates usage across attempts but keeps the longest elapsed time', function (): void {
    $first = new BudgetUsage(steps: 2, promptTokens: 100, elapsedSeconds: 30);
    $second = new BudgetUsage(steps: 3, promptTokens: 50, elapsedSeconds: 10);

    $total = $first->add($second);

    expect($total->steps)->toBe(5)
        ->and($total->promptTokens)->toBe(150)
        ->and($total->elapsedSeconds)->toBe(30);
});

it('starts a first attempt with no prior usage', function (): void {
    $manager = new BudgetManager;

    expect($manager->startingUsageFor(null)->steps)->toBe(0)
        ->and($manager->startingUsageFor(null, reset: true)->promptTokens)->toBe(0);

    // Carrying usage forward from a real previous attempt is covered end to end
    // in tests/Feature/CancellationAndBudgetTest.php.
});

it('prices usage from the configured rate table', function (): void {
    $estimator = new CostEstimator([
        'anthropic:claude-sonnet-4-5' => ['input' => 3.00, 'output' => 15.00],
    ]);

    $usage = new BudgetUsage(promptTokens: 1_000_000, completionTokens: 100_000);

    expect($estimator->estimate($usage, 'anthropic', 'claude-sonnet-4-5'))->toBe(4.5);
});

it('contributes nothing for an unpriced model rather than guessing', function (): void {
    $estimator = new CostEstimator([]);

    expect($estimator->estimate(new BudgetUsage(promptTokens: 1_000_000), 'openai', 'gpt-5'))->toBe(0.0)
        ->and($estimator->hasRateFor('openai', 'gpt-5'))->toBeFalse();
});

it('falls back from a provider-qualified rate to a bare model name', function (): void {
    $estimator = new CostEstimator(['gpt-5' => ['input' => 1.25, 'output' => 10.00]]);

    expect($estimator->estimate(new BudgetUsage(promptTokens: 1_000_000), 'openai', 'gpt-5'))->toBe(1.25);
});

it('prices a dated model snapshot at its base rate', function (): void {
    $estimator = new CostEstimator([
        'openai:gpt-5.1' => ['input' => 1.25, 'output' => 10.00],
    ]);

    $usage = new BudgetUsage(promptTokens: 1_000_000, completionTokens: 100_000);

    // Providers answer `gpt-5.1` requests as `gpt-5.1-2025-11-13`. Pricing the
    // response verbatim missed the configured rate, estimated $0.00, and let a
    // maxCostUsd budget spend without ever triggering.
    expect($estimator->estimate($usage, 'openai', 'gpt-5.1-2025-11-13'))->toBe(2.25)
        ->and($estimator->hasRateFor('openai', 'gpt-5.1-2025-11-13'))->toBeTrue()
        // A genuinely unknown model still refuses to guess.
        ->and($estimator->estimate($usage, 'openai', 'gpt-9'))->toBe(0.0);
});
