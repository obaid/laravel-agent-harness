<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Workflows\Workflow;

/**
 * Counts how many times each step body actually executes.
 *
 * The counters are the whole point: they are what proves a resumed workflow
 * skipped the work it had already done rather than merely returning the same
 * answer twice.
 */
class CountingWorkflow extends Workflow
{
    /** @var array<string, int> */
    public static array $ran = [];

    public static function reset(): void
    {
        static::$ran = [];
    }

    public static function count(string $step): int
    {
        return static::$ran[$step] ?? 0;
    }

    public function handle(array $payload): mixed
    {
        $first = $this->step('first', function () use ($payload) {
            static::$ran['first'] = static::count('first') + 1;

            return strtoupper((string) ($payload['name'] ?? 'nobody'));
        });

        $this->emit('greeted', ['who' => $first]);

        $decision = $this->pause('sign-off', ['who' => $first], 'Confirm before finishing.');

        $second = $this->step('second', function () use ($first, $decision) {
            static::$ran['second'] = static::count('second') + 1;

            return $first.':'.($decision['approved'] ? 'yes' : 'no');
        });

        return ['first' => $first, 'second' => $second];
    }
}
