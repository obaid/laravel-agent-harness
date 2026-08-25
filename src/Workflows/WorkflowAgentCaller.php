<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use Clutch\Laravel\Approvals\ApprovalBroker;
use Clutch\Laravel\Data\PendingApproval;
use Clutch\Laravel\Enums\ApprovalStatus;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Runtime\RunCoordinator;

/**
 * Prompts agents on a workflow's behalf.
 *
 * Each agent a workflow uses gets a session of its own, created once and then
 * reused, so a second prompt to the same agent continues the conversation
 * rather than starting from nothing. Those sessions are children of the
 * workflow's, which is what lets `clutch:events` show the agent's work nested
 * under the job that asked for it.
 */
final class WorkflowAgentCaller
{
    public function __construct(
        protected RunCoordinator $coordinator,
        protected ApprovalBroker $approvals,
    ) {}

    /**
     * @param  class-string  $agentClass
     * @param  array<string, mixed>  $options
     */
    public function prompt(
        Run $run,
        WorkflowState $state,
        string $prompt,
        string $agentClass,
        array $options = [],
    ): ClutchResult {
        $session = $this->sessionFor($run, $state, $agentClass);

        // The agent may already be parked from an earlier pass, waiting on the
        // decision that resumed this workflow. Carry that decision through to
        // it rather than starting a second prompt it cannot accept.
        $resumed = $this->resumeIfWaiting($session, $state, $agentClass);

        $result = $resumed ?? $this->coordinator->promptNow($session, $prompt, [], $options);

        if ($result->isAwaitingApproval()) {
            // Never let an unfinished prompt look finished. Unwinding here is
            // what keeps the surrounding step unrecorded, so re-entry runs it
            // again instead of trusting a result the agent never produced.
            throw new AgentPaused(
                $agentClass,
                $result->run->id,
                $result->pendingApprovals
                    ->map(fn (Approval $approval): PendingApproval => new PendingApproval(
                        toolCallId: $approval->tool_call_id,
                        toolName: $approval->tool_name,
                        arguments: $approval->arguments ?? [],
                        reason: $approval->reason,
                    ))
                    ->all(),
            );
        }

        return $result;
    }

    /**
     * Carry the workflow's decision through to an agent that is already parked.
     *
     * @param  class-string  $agentClass
     */
    protected function resumeIfWaiting(Session $session, WorkflowState $state, string $agentClass): ?ClutchResult
    {
        $active = $session->active_run_id === null
            ? null
            : Run::query()->find($session->active_run_id);

        if ($active === null || $active->status !== RunStatus::AwaitingApproval) {
            return null;
        }

        $decision = $state->resumeInput[$this->pauseKey($agentClass)] ?? null;

        if ($decision === null) {
            // Parked with nothing to answer it. Surface the same pause again
            // rather than silently blocking.
            return ClutchResult::fromRun($active);
        }

        $approved = (bool) ($decision['approved'] ?? false);
        $reason = isset($decision['reason']) ? (string) $decision['reason'] : null;

        foreach ($active->approvals()->where('status', 'pending')->get() as $approval) {
            $this->approvals->resolve(
                $active,
                $approval->id,
                $approved ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                $reason,
            );
        }

        // A rejection is an answer too: it reaches the agent as a tool result
        // it can respond to, which is what lets the run finish either way.
        return ClutchResult::fromRun($this->coordinator->continueRun($active->id)->refresh());
    }

    /**
     * The pause name an agent's approval is answered under.
     *
     * @param  class-string  $agentClass
     */
    public function pauseKey(string $agentClass): string
    {
        return 'agent:'.class_basename($agentClass);
    }

    /**
     * @param  class-string  $agentClass
     */
    protected function sessionFor(Run $run, WorkflowState $state, string $agentClass): Session
    {
        $existing = $state->sessions[$agentClass] ?? null;

        if ($existing !== null) {
            $session = Session::query()->find($existing);

            if ($session !== null && ! $session->status->isTerminal()) {
                return $session;
            }
        }

        $parent = $run->session;

        $session = $this->coordinator->createSession([
            'agent_class' => $agentClass,
            'driver' => (string) config('clutch.default_driver', 'laravel-ai'),
            'name' => class_basename($agentClass).' · '.class_basename($state->workflow),
            'permission_mode' => $parent->permission_mode,
            'participant_type' => $parent->participant_type,
            'participant_id' => $parent->participant_id,
            'tenant_type' => $parent->tenant_type,
            'tenant_id' => $parent->tenant_id,
            'queue_connection' => $parent->queue_connection,
            'queue' => $parent->queue,
            'timeout_seconds' => $parent->timeout_seconds,
            'configuration' => $parent->configuration ?? [],
            'metadata' => [
                // What makes the agent's runs findable from the workflow that
                // caused them, and the reason the two are not separate stories.
                'workflow' => $state->workflow,
                'workflow_session_id' => $parent->id,
                'workflow_run_id' => $run->id,
            ],
        ]);

        $state->sessions[$agentClass] = $session->id;

        return $session;
    }
}
