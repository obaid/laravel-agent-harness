<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Exceptions;

final class BudgetExceeded extends HarnessException
{
    public function errorCode(): string
    {
        return 'budget_exceeded';
    }

    public function statusCode(): int
    {
        return 402;
    }

    public static function forLimit(string $limit, int|float $used, int|float $max): self
    {
        return new self("The run budget limit [{$limit}] was exhausted ({$used} of {$max}).");
    }
}
