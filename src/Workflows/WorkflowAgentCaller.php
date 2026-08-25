<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

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
    public function __construct(protected RunCoordinator $coordinator) {}

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

        return $this->coordinator->promptNow($session, $prompt, [], $options);
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
