<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use Clutch\Laravel\Approvals\ApprovalBroker;
use Clutch\Laravel\Enums\ApprovalStatus;
use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Exceptions\RunNotFound;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Runtime\RunCoordinator;
use Clutch\Laravel\ValueObjects\RunBudget;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Starts and resumes workflow runs.
 *
 * Everything here goes through the coordinator rather than around it. A
 * workflow gets the same leases, budgets, cancellation, event log and orphan
 * recovery as any other run, because it is one.
 */
final class WorkflowRunner
{
    public function __construct(
        protected RunCoordinator $coordinator,
        protected ApprovalBroker $approvals,
    ) {}

    /**
     * Create the session and queue the run.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $payload
     */
    public function queue(array $definition, array $payload): Run
    {
        $session = $this->createSession($definition);

        return $this->coordinator->queueRun(
            $session,
            $this->describe($definition),
            options: $this->runOptions($definition, $payload),
        );
    }

    /**
     * Create the session and run it inline.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $payload
     */
    public function runNow(array $definition, array $payload): ClutchResult
    {
        $session = $this->createSession($definition);

        return $this->coordinator->promptNow(
            $session,
            $this->describe($definition),
            options: $this->runOptions($definition, $payload),
        );
    }

    /**
     * Answer a paused workflow and let the queue carry it on.
     *
     * @param  array<string, mixed>  $input
     */
    public function resume(string $runId, array $input = []): Run
    {
        $run = $this->answer($runId, $input);

        return $this->coordinator->resumeAfterApproval($run);
    }

    /**
     * Answer a paused workflow and carry it on inline.
     *
     * @param  array<string, mixed>  $input
     */
    public function resumeNow(string $runId, array $input = []): ClutchResult
    {
        $run = $this->answer($runId, $input);

        $run = $this->coordinator->continueRun($run->id);

        return ClutchResult::fromRun($run->refresh());
    }

    /**
     * Record the answer against whatever the workflow is waiting on.
     *
     * An `approved: false` input is still an answer. The workflow decides what
     * a rejection means, rather than the harness killing the run for it.
     *
     * @param  array<string, mixed>  $input
     */
    protected function answer(string $runId, array $input): Run
    {
        $run = Run::query()->with('session')->find($runId) ?? throw RunNotFound::withId($runId);

        if ($run->status !== RunStatus::AwaitingApproval) {
            throw new LogicException(sprintf(
                'Run [%s] is [%s], not paused, so there is nothing to answer.',
                $runId,
                $run->status->value,
            ));
        }

        $approved = ! array_key_exists('approved', $input) || (bool) $input['approved'];
        $reason = isset($input['reason']) ? (string) $input['reason'] : null;

        /** @var iterable<Approval> $pending */
        $pending = $run->approvals()->where('status', 'pending')->get();

        foreach ($pending as $approval) {
            // The whole input is carried across as edited arguments, which is
            // how the body reads what the resume actually supplied.
            $this->approvals->resolve(
                $run,
                $approval->id,
                $approved ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                $reason,
                null,
                $input,
            );
        }

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function createSession(array $definition): Session
    {
        /** @var class-string<Workflow> $workflow */
        $workflow = $definition['workflow'];

        $participant = $definition['participant'] ?? null;
        $tenant = $definition['tenant'] ?? null;

        return $this->coordinator->createSession([
            'driver' => 'workflow',
            'runtime_name' => 'workflow',
            // Deliberately no agent_class: a workflow session is not an agent
            // session. The agents it prompts get sessions of their own, and
            // conflating the two makes a workflow indistinguishable from them.
            'agent_class' => null,
            'name' => $definition['name'],
            'permission_mode' => $definition['permission_mode'] ?? PermissionMode::ApproveSensitive,
            'participant_type' => $participant instanceof Model ? $participant->getMorphClass() : null,
            'participant_id' => $participant instanceof Model ? (string) $participant->getKey() : null,
            'tenant_type' => $tenant instanceof Model ? $tenant->getMorphClass() : null,
            'tenant_id' => $tenant instanceof Model ? (string) $tenant->getKey() : null,
            'queue_connection' => $definition['queue_connection'] ?? null,
            'queue' => $definition['queue'] ?? null,
            'timeout_seconds' => $definition['timeout_seconds'] ?? null,
            'configuration' => array_filter([
                'workflow' => $workflow,
                'sandbox' => $workflow::sandboxProvider(),
            ], static fn (mixed $value): bool => $value !== null),
            'metadata' => array_filter([
                'workflow' => $workflow,
                ...($definition['metadata'] ?? []),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function runOptions(array $definition, array $payload): array
    {
        $budget = $definition['budget'] ?? null;

        return array_filter([
            'input_type' => 'workflow',
            'input' => ['payload' => $payload],
            'budget' => $budget instanceof RunBudget ? $budget->toArray() : null,
            'metadata' => ['workflow' => $definition['workflow']],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * What the run's prompt column reads as. Workflows have no prompt, but a
     * run is easier to recognise in a list with something human in it.
     *
     * @param  array<string, mixed>  $definition
     */
    protected function describe(array $definition): string
    {
        return (string) $definition['name'];
    }
}
