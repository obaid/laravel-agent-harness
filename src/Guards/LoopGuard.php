<?php

declare(strict_types=1);

namespace Clutch\Laravel\Guards;

use Clutch\Laravel\Data\ToolInvocation;

/**
 * Watches a run for the shapes that mean an agent is stuck.
 *
 * Budgets catch a run that is expensive. They do not catch a run that is
 * cheap and useless: an agent calling the same tool with the same arguments
 * forty times will pass every token ceiling while making no progress. This
 * counts repeats and hands back an advisory the driver can put in front of the
 * model, or a refusal once the repetition is clearly pathological.
 *
 * The guard is deliberately advisory first. An agent legitimately re-reads a
 * file it has just written, and killing a run on the second identical call
 * would break more than it fixes.
 */
class LoopGuard
{
    /**
     * Digest of each invocation seen this run, mapped to how often it appeared.
     *
     * @var array<string, int>
     */
    protected array $seen = [];

    public function __construct(
        /** Identical calls tolerated before the model is reminded. */
        protected int $remindAfter = 3,
        /** Identical calls tolerated before the call is refused outright. */
        protected int $blockAfter = 8,
        protected bool $enabled = true,
    ) {}

    /**
     * Record a tool call and decide what, if anything, to do about it.
     */
    public function inspect(ToolInvocation $invocation): GuardDecision
    {
        if (! $this->enabled) {
            return GuardDecision::proceed();
        }

        $key = $this->keyFor($invocation);

        $count = $this->seen[$key] = ($this->seen[$key] ?? 0) + 1;

        if ($this->blockAfter > 0 && $count > $this->blockAfter) {
            return GuardDecision::block(
                "The tool [{$invocation->toolName}] has been called {$count} times with identical ".
                'arguments in this run. The result will not change. Use what you already have, or '.
                'try a different approach.'
            );
        }

        if ($this->remindAfter > 0 && $count > $this->remindAfter) {
            return GuardDecision::remind(
                "You have now called [{$invocation->toolName}] {$count} times with the same arguments ".
                'and received the same result each time. Repeating it will not produce anything new.'
            );
        }

        return GuardDecision::proceed();
    }

    /**
     * How often an identical call has been seen this run.
     */
    public function timesSeen(ToolInvocation $invocation): int
    {
        return $this->seen[$this->keyFor($invocation)] ?? 0;
    }

    /**
     * Identical calls are only "the same" within one run.
     *
     * A queue worker handles many runs in its lifetime, so leaving the run out
     * would let one run's repetition count against the next one's budget.
     */
    protected function keyFor(ToolInvocation $invocation): string
    {
        return $invocation->runId.':'.$invocation->toolName.':'.$invocation->argumentsDigest();
    }

    /**
     * Forget everything, for a fresh run on a reused instance.
     */
    public function reset(): static
    {
        $this->seen = [];

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
