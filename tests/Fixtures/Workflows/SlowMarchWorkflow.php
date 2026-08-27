<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Workflows\Workflow;

/**
 * Three sequential steps that count their executions, for slicing tests:
 * the whole point of a slice is that a step runs exactly once however many
 * queue jobs the workflow is spread across.
 */
final class SlowMarchWorkflow extends Workflow
{
    /** @var array<string, int> */
    public static array $executions = [];

    public static function reset(): void
    {
        self::$executions = [];
    }

    public function handle(array $payload): mixed
    {
        $label = (string) ($payload['label'] ?? 'work');
        $results = [];

        foreach (['one' => 1, 'two' => 2, 'three' => 3] as $name => $number) {
            $results[$name] = $this->step($name, function () use ($label, $name, $number): string {
                self::$executions[$name] = (self::$executions[$name] ?? 0) + 1;

                return "{$label}-{$number}";
            });
        }

        return $results;
    }
}
