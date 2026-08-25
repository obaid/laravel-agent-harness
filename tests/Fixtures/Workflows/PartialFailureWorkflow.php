<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Workflows\Workflow;
use RuntimeException;

/**
 * One of two concurrent steps fails. The README claims a resume re-runs only
 * the one that was missing, so this is where that claim is kept honest.
 */
class PartialFailureWorkflow extends Workflow
{
    public static bool $failSecond = true;

    /** @var array<int, string> */
    public static array $ran = [];

    public static function reset(): void
    {
        static::$failSecond = true;
        static::$ran = [];
    }

    public function handle(array $payload): mixed
    {
        $data = $this->steps([
            'good' => function (): string {
                static::$ran[] = 'good';

                return 'fetched';
            },
            'flaky' => function (): string {
                static::$ran[] = 'flaky';

                if (static::$failSecond) {
                    throw new RuntimeException('the upstream was down');
                }

                return 'fetched late';
            },
        ]);

        return $data;
    }
}
