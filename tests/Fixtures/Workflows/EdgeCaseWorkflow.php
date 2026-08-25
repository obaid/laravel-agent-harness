<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Workflows\Workflow;

/**
 * The awkward shapes: several pauses, a pause inside a loop, and a step that
 * returns something a JSON round trip would quietly mangle.
 */
class EdgeCaseWorkflow extends Workflow
{
    /** @var array<int, string> */
    public static array $ran = [];

    public static function reset(): void
    {
        static::$ran = [];
    }

    public function handle(array $payload): mixed
    {
        $mode = (string) ($payload['mode'] ?? 'two-pauses');

        return match ($mode) {
            'two-pauses' => $this->twoPauses(),
            'loop' => $this->pauseInLoop((array) ($payload['items'] ?? [])),
            'null-step' => $this->nullStep(),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function twoPauses(): array
    {
        $first = $this->pause('first-gate', ['which' => 1]);

        $middle = $this->step('middle', function (): string {
            static::$ran[] = 'middle';

            return 'did the middle';
        });

        $second = $this->pause('second-gate', ['which' => 2]);

        return ['first' => $first['approved'], 'second' => $second['approved'], 'middle' => $middle];
    }

    /**
     * @param  array<int, string>  $items
     * @return array<string, mixed>
     */
    protected function pauseInLoop(array $items): array
    {
        $done = [];

        foreach ($items as $item) {
            $decision = $this->pause('approve:'.$item, ['item' => $item]);

            if ($decision['approved'] ?? false) {
                $done[] = $this->step('do:'.$item, function () use ($item): string {
                    static::$ran[] = $item;

                    return 'did '.$item;
                });
            }
        }

        return ['done' => $done];
    }

    /**
     * @return array<string, mixed>
     */
    protected function nullStep(): array
    {
        // A step that legitimately returns null must not be mistaken for one
        // that never ran, or it repeats on every resume.
        $nothing = $this->step('returns-null', function () {
            static::$ran[] = 'null-step';

            return null;
        });

        $this->pause('gate', []);

        return ['nothing' => $nothing, 'ran' => count(static::$ran)];
    }
}
