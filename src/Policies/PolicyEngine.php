<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Policies;

use AgentHarness\Laravel\Contracts\SensitiveTool;
use AgentHarness\Laravel\Data\ToolInvocation;
use AgentHarness\Laravel\Enums\PermissionMode;
use AgentHarness\Laravel\Enums\ToolSensitivity;
use Closure;

/**
 * Combines permission mode, tool sensitivity, and application callbacks.
 *
 * The most restrictive result wins. Tool authorization is evaluated
 * immediately before execution, not merely when the model requests the tool,
 * so a permission revoked mid-run still takes effect.
 */
class PolicyEngine
{
    /** @var array<int, Closure(ToolInvocation, ToolSensitivity): ?PolicyDecision> */
    protected array $callbacks = [];

    /**
     * @param  array<string, string>  $sensitivityMap  tool name => ToolSensitivity value
     * @param  array<int, string>  $alwaysAllow  tool names permitted even under deny-by-default
     */
    public function __construct(
        protected array $sensitivityMap = [],
        protected array $alwaysAllow = [],
    ) {}

    /**
     * Register an application rule.
     *
     * Returning null defers to the remaining rules; returning a decision
     * participates in the restrictive merge.
     *
     * @param  Closure(ToolInvocation, ToolSensitivity): ?PolicyDecision  $callback
     */
    public function extend(Closure $callback): static
    {
        $this->callbacks[] = $callback;

        return $this;
    }

    /**
     * Decide whether a tool call may proceed.
     */
    public function decide(ToolInvocation $invocation, ?object $tool = null): PolicyDecision
    {
        $sensitivity = $this->sensitivityOf($invocation->toolName, $tool);

        $decision = $this->baseDecision($invocation, $sensitivity);

        foreach ($this->callbacks as $callback) {
            $result = $callback($invocation, $sensitivity);

            if ($result instanceof PolicyDecision) {
                $decision = $decision->mergeRestrictive($result);
            }
        }

        return $decision;
    }

    /**
     * Classify a tool, treating unknown tools as sensitive.
     */
    public function sensitivityOf(string $toolName, ?object $tool = null): ToolSensitivity
    {
        if ($tool instanceof SensitiveTool) {
            return $tool->sensitivity();
        }

        $configured = $this->sensitivityMap[$toolName] ?? null;

        if (is_string($configured)) {
            return ToolSensitivity::tryFrom($configured) ?? ToolSensitivity::default();
        }

        return ToolSensitivity::default();
    }

    /**
     * Register a tool's sensitivity at runtime.
     */
    public function classify(string $toolName, ToolSensitivity $sensitivity): static
    {
        $this->sensitivityMap[$toolName] = $sensitivity->value;

        return $this;
    }

    /**
     * The decision implied by the session's permission mode alone.
     */
    protected function baseDecision(ToolInvocation $invocation, ToolSensitivity $sensitivity): PolicyDecision
    {
        $mode = $invocation->permissionMode;

        if (in_array($invocation->toolName, $this->alwaysAllow, true)) {
            return PolicyDecision::allow();
        }

        // Deny-by-default refuses outright rather than asking, since an
        // un-allowlisted tool in that mode is a configuration error.
        if ($mode === PermissionMode::DenyByDefault && $sensitivity !== ToolSensitivity::ReadOnly) {
            return PolicyDecision::deny(
                "The tool [{$invocation->toolName}] is not on the allow list for this session."
            );
        }

        if ($mode->requiresApprovalFor($sensitivity)) {
            return PolicyDecision::requireApproval(
                "The tool [{$invocation->toolName}] is classified as [{$sensitivity->value}]."
            );
        }

        return PolicyDecision::allow();
    }
}
