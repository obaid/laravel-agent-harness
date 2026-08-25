<?php

declare(strict_types=1);

namespace Clutch\Laravel\Policies;

use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Runtime\RunContext;
use Clutch\Laravel\Tools\GuardedTool;
use Clutch\Laravel\Tools\ToolExecutionLedger;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\ToolNameResolver;

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
    public function __construct(
        protected PolicyEngine $policy,
        protected ToolExecutionLedger $ledger,
    ) {}

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
        $configuration = (array) ($context->session->configuration ?? []);

        /** @var array<int, string> $active */
        $active = (array) ($configuration['active_tools'] ?? []);
        /** @var array<int, string> $inactive */
        $inactive = (array) ($configuration['inactive_tools'] ?? []);

        $allowed = [];

        foreach ($tools as $tool) {
            $name = $this->nameOf($tool);

            // An allow list, when present, is absolute: anything not named on
            // it is withheld regardless of how safe the policy thinks it is.
            if ($active !== [] && ! $this->named($name, $active)) {
                $context->log('info', 'A tool was withheld because the session names an allow list.', [
                    'tool' => $name,
                ]);

                continue;
            }

            if ($this->named($name, $inactive)) {
                $context->log('info', 'A tool was withheld because the session denies it.', [
                    'tool' => $name,
                ]);

                continue;
            }

            $decision = $this->policy->decide(
                $context->invocationFor($name, 'policy-preflight'),
                $tool,
            );

            if ($decision->isDenied()) {
                $context->log('info', 'A tool was withheld from the agent by Clutch policy.', [
                    'tool' => $name,
                    'reason' => $decision->reason,
                    'permission_mode' => $mode->value,
                ]);

                continue;
            }

            if ($decision->requiresApproval() && $tool instanceof Approvable) {
                $tool = $tool->requireApproval($decision->reason);
            }

            // Wrapping last, so the ledger, the loop guard, the deadline and
            // the spill policy all sit in front of the call. Without this the
            // ledger is a table nothing ever writes to.
            $allowed[] = $tool instanceof Tool
                ? GuardedTool::wrap($tool, $this->ledger)
                : $tool;
        }

        return $allowed;
    }

    /**
     * The name a tool is known by everywhere else.
     *
     * Deliberately Laravel AI's own resolution rather than a variant of it, so
     * one name reaches the model, the approval record, the event history and
     * the ledger. Configuration may still be written in snake_case; the policy
     * engine accepts either.
     */
    public function nameOf(object $tool): string
    {
        if ($tool instanceof GuardedTool) {
            $tool = $tool->inner();
        }

        return $tool instanceof Tool
            ? ToolNameResolver::resolve($tool)
            : class_basename($tool);
    }

    /**
     * Match a tool name against a list written in either spelling.
     *
     * @param  array<int, string>  $names
     */
    protected function named(string $name, array $names): bool
    {
        return in_array($name, $names, true)
            || in_array(\Illuminate\Support\Str::snake($name), $names, true);
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
