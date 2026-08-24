<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Budgets;

use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Models\Session;
use AgentHarness\Laravel\ValueObjects\BudgetUsage;
use AgentHarness\Laravel\ValueObjects\RunBudget;

/**
 * Combines configured, session, and run budgets and decides when to stop.
 *
 * Budgets apply across attempts by default: a retry inherits the consumption of
 * the run it retries, so a failing loop cannot spend the limit repeatedly.
 */
class BudgetManager
{
    public function __construct(protected RunBudget $defaults = new RunBudget) {}

    /**
     * Resolve the effective budget for a run: the most restrictive limit from
     * each of the configured defaults, the session, and the run itself.
     */
    public function effectiveBudget(Session $session, ?Run $run = null): RunBudget
    {
        return $this->defaults
            ->mergeRestrictive(RunBudget::fromArray($session->getAttribute('budget') ?? []))
            ->mergeRestrictive($run ? RunBudget::fromArray($run->getAttribute('budget') ?? []) : null);
    }

    /**
     * Determine which limit, if any, has been exhausted.
     *
     * Returns the name of the exhausted limit, or null while there is headroom.
     */
    public function exhaustedLimit(RunBudget $budget, BudgetUsage $usage): ?string
    {
        return match (true) {
            $budget->maxSteps !== null && $usage->steps >= $budget->maxSteps => 'max_steps',
            $budget->maxToolCalls !== null && $usage->toolCalls >= $budget->maxToolCalls => 'max_tool_calls',
            $budget->maxTokens !== null && $usage->totalTokens() >= $budget->maxTokens => 'max_tokens',
            $budget->maxCostUsd !== null && $usage->costUsd >= $budget->maxCostUsd => 'max_cost_usd',
            $budget->maxDurationSeconds !== null && $usage->elapsedSeconds >= $budget->maxDurationSeconds => 'max_duration_seconds',
            default => null,
        };
    }

    /**
     * Determine whether the budget still allows another model step or tool call.
     */
    public function allowsAnotherStep(RunBudget $budget, BudgetUsage $usage): bool
    {
        return $this->exhaustedLimit($budget, $usage) === null;
    }

    /**
     * Describe an exhausted limit for the terminal event payload.
     *
     * @return array<string, mixed>
     */
    public function describeExhaustion(string $limit, RunBudget $budget, BudgetUsage $usage): array
    {
        return [
            'limit' => $limit,
            'max' => $budget->toArray()[$limit] ?? null,
            'used' => match ($limit) {
                'max_steps' => $usage->steps,
                'max_tool_calls' => $usage->toolCalls,
                'max_tokens' => $usage->totalTokens(),
                'max_cost_usd' => $usage->costUsd,
                'max_duration_seconds' => $usage->elapsedSeconds,
                default => null,
            },
            'usage' => $usage->toArray(),
        ];
    }

    /**
     * The starting consumption for a new attempt.
     *
     * Carrying prior usage forward is what makes budgets meaningful across
     * retries; resetting is an explicit application choice.
     */
    public function startingUsageFor(?Run $previousAttempt, bool $reset = false): BudgetUsage
    {
        if ($reset || ! $previousAttempt instanceof Run) {
            return new BudgetUsage;
        }

        return $previousAttempt->usage();
    }

    public function defaults(): RunBudget
    {
        return $this->defaults;
    }
}
