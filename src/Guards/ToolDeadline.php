<?php

declare(strict_types=1);

namespace Clutch\Laravel\Guards;

use Closure;
use Clutch\Laravel\Data\ToolInvocation;
use Clutch\Laravel\Exceptions\ToolTimedOut;
use Throwable;

/**
 * Bounds how long one tool call may take.
 *
 * A run-level duration budget only notices an overrun once the tool returns,
 * which is no help when the tool is the thing that hung. This arms a deadline
 * around the call itself, so a tool waiting forever on a socket fails as a
 * tool error the agent can react to rather than stalling the whole run until
 * the worker is killed.
 *
 * PHP cannot interrupt arbitrary blocking work, so this is enforced with an
 * alarm where the runtime supports one, and checked after the fact otherwise.
 * The package does not claim to interrupt a tool that cannot be interrupted.
 */
class ToolDeadline
{
    /**
     * @param  array<string, int>  $perTool  seconds, keyed by tool name
     */
    public function __construct(
        protected ?int $defaultSeconds = null,
        protected array $perTool = [],
    ) {}

    /**
     * The deadline that applies to a given call, if any.
     */
    public function secondsFor(string $toolName): ?int
    {
        return $this->perTool[$toolName] ?? $this->defaultSeconds;
    }

    /**
     * Run a tool under its deadline.
     *
     * @param  Closure(): mixed  $execute
     *
     * @throws ToolTimedOut
     */
    public function guard(ToolInvocation $invocation, Closure $execute): mixed
    {
        $seconds = $this->secondsFor($invocation->toolName);

        if ($seconds === null || $seconds <= 0) {
            return $execute();
        }

        $startedAt = microtime(true);

        // pcntl gives a real interrupt. Without it the call still runs to
        // completion and is reported afterwards, which is honest but weaker.
        $canInterrupt = function_exists('pcntl_async_signals')
            && function_exists('pcntl_alarm');

        if (! $canInterrupt) {
            $result = $execute();

            $this->assertWithinDeadline($invocation, $seconds, microtime(true) - $startedAt);

            return $result;
        }

        $previous = pcntl_async_signals(true);

        pcntl_signal(SIGALRM, function () use ($invocation, $seconds): void {
            throw ToolTimedOut::after($invocation->toolName, $seconds);
        });

        pcntl_alarm($seconds);

        try {
            return $execute();
        } catch (Throwable $e) {
            throw $e;
        } finally {
            pcntl_alarm(0);
            pcntl_async_signals($previous);
        }
    }

    /**
     * @throws ToolTimedOut
     */
    protected function assertWithinDeadline(ToolInvocation $invocation, int $seconds, float $elapsed): void
    {
        if ($elapsed > $seconds) {
            throw ToolTimedOut::after($invocation->toolName, $seconds, (int) round($elapsed));
        }
    }
}
