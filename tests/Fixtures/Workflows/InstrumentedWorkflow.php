<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Workflows\Workflow;
use RuntimeException;

/**
 * A workflow that counts every side effect and can be made to fail at any
 * point in it.
 *
 * The counters are the assertion. However a run is interrupted, and however
 * many times it is resumed, retried or reaped, each of these must have
 * happened exactly once.
 */
class InstrumentedWorkflow extends Workflow
{
    /** The steps this workflow performs, in order. */
    public const STEPS = ['alpha', 'beta', 'gamma'];

    /** @var array<string, int> */
    public static array $effects = [];

    /** Throw the moment this step's body is entered. */
    public static ?string $failAt = null;

    public static function reset(): void
    {
        static::$effects = [];
        static::$failAt = null;
    }

    public static function count(string $step): int
    {
        return static::$effects[$step] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = [];

        foreach (self::STEPS as $step) {
            $counts[$step] = static::count($step);
        }

        return $counts;
    }

    public function handle(array $payload): mixed
    {
        $out = [];

        foreach (self::STEPS as $step) {
            $out[$step] = $this->step($step, function () use ($step): string {
                if (static::$failAt === $step) {
                    // Counted before throwing: a side effect that half
                    // happened is still a side effect, and the ledger has to
                    // be honest about that.
                    static::$effects[$step] = static::count($step) + 1;

                    throw new RuntimeException("[{$step}] blew up");
                }

                static::$effects[$step] = static::count($step) + 1;

                return 'did '.$step;
            });

            if (($payload['pause_after'] ?? null) === $step) {
                $this->pause('after:'.$step, ['step' => $step]);
            }
        }

        return $out;
    }
}
