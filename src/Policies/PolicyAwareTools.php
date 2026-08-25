<?php

declare(strict_types=1);

namespace Clutch\Laravel\Policies;

use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Runtime\RunContext;
use Laravel\Ai\Contracts\Approvable;

/**
 * Applies a session's permission mode to an agent's tool list.
 *
 * Laravel AI asks the agent for its tools on every turn, and a tool list is
 * usually built fresh each time, so the harness cannot reach in and rewrite it
 * after the fact. Instead the agent hands its tools through here:
 *
 *     public function tools(): iterable
 *     {
 *         return Clutch::policy([new SearchWeb, new PublishArticle]);
 *     }
 *
 * Outside a harness run this is a no-op, so the same agent still behaves
 * normally when prompted directly through Laravel AI.
 */
class PolicyAwareTools
{
    public function __construct(protected PolicyEngine $policy) {}

    /**
     * Filter and annotate tools according to the current session's policy.
     *
     * A tool the policy denies is removed, so the model is never told it
     * exists — refusing after the fact would still have exposed the capability.
     * A tool that needs sign-off is marked approvable, which is what makes it
     * pause and become a durable harness approval.
     *
     * @param  iterable<int, object>  $tools
     * @return array<int, object>
     */
    public function apply(iterable $tools): array
    {
        $context = RunContext::current();

        if (! $context instanceof RunContext) {
            return is_array($tools) ? array_values($tools) : iterator_to_array($tools, false);
        }

        $mode = $context->permissionMode();
        $allowed = [];

        foreach ($tools as $tool) {
            $decision = $this->policy->decide(
                $context->invocationFor($this->nameOf($tool), 'policy-preflight'),
                $tool,
            );

            if ($decision->isDenied()) {
                $context->log('info', 'A tool was withheld from the agent by harness policy.', [
                    'tool' => $this->nameOf($tool),
                    'reason' => $decision->reason,
                    'permission_mode' => $mode->value,
                ]);

                continue;
            }

            if ($decision->requiresApproval() && $tool instanceof Approvable) {
                $tool = $tool->requireApproval($decision->reason);
            }

            $allowed[] = $tool;
        }

        return $allowed;
    }

    /**
     * The name the policy engine knows a tool by.
     *
     * Mirrors Laravel AI's own convention: the snake-cased short class name,
     * unless the tool names itself.
     */
    public function nameOf(object $tool): string
    {
        if (method_exists($tool, 'name')) {
            return (string) $tool->name();
        }

        return \Illuminate\Support\Str::snake(class_basename($tool));
    }

    /**
     * The permission mode in effect, or the configured default outside a run.
     */
    public function currentMode(): PermissionMode
    {
        return RunContext::current()?->permissionMode()
            ?? PermissionMode::tryFrom((string) config('clutch.permissions.default'))
            ?? PermissionMode::ApproveSensitive;
    }
}
