<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use Clutch\Laravel\Contracts\ClutchDriver;
use Clutch\Laravel\Contracts\DriverEventSink;
use Clutch\Laravel\Data\Continuation;
use Clutch\Laravel\Data\DriverCheckpoint;
use Clutch\Laravel\Data\DriverSession;
use Clutch\Laravel\Data\PendingApproval;
use Clutch\Laravel\Data\StartSession;
use Clutch\Laravel\Data\TurnInput;
use Clutch\Laravel\Data\TurnResult;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\CancellationSignal;
use Clutch\Laravel\ValueObjects\DriverCapabilities;
use Clutch\Laravel\ValueObjects\NormalizedFailure;
use Clutch\Laravel\ValueObjects\TurnLimits;
use InvalidArgumentException;
use Throwable;

/**
 * Runs a workflow as a harness session.
 *
 * A workflow is a runtime like any other from the coordinator's point of
 * view, which is the point: it inherits leases, budgets, cancellation, the
 * event log, checkpointing and orphan recovery rather than reimplementing
 * them. What is specific to workflows lives here and nowhere else.
 *
 * Step results are the driver's state, so the existing checkpoint machinery
 * is exactly what makes a resume skip work that already happened.
 */
final class WorkflowDriver implements ClutchDriver
{
    public const SCHEMA_VERSION = 1;

    public function __construct(protected WorkflowAgentCaller $agents) {}

    public function name(): string
    {
        return 'workflow';
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            streaming: false,
            hostTools: false,
            nativeTools: false,
            // A pause is resolved on the approval rails, so this is true in
            // the sense the harness cares about: the run parks and something
            // outside it decides when to carry on.
            approvals: true,
            structuredOutput: true,
            sessionResume: true,
            inFlightContinuation: true,
            manualCompaction: false,
            // Sliced between steps: the runtime hands the turn back at the
            // first step boundary past the budget, the run suspends, and a
            // continuation re-enters handle() with the finished steps cached.
            timeSlicing: true,
        );
    }

    public function start(StartSession $command): DriverSession
    {
        $workflow = (string) $command->config('workflow', '');

        if ($workflow === '' || ! is_subclass_of($workflow, Workflow::class)) {
            throw new InvalidArgumentException(sprintf(
                'A workflow session needs a [workflow] configuration naming a %s subclass. Got [%s].',
                Workflow::class,
                $workflow === '' ? 'nothing' : $workflow,
            ));
        }

        return new DriverSession(
            $command->sessionId,
            $this->name(),
            (new WorkflowState($workflow))->toArray(),
        );
    }

    public function runTurn(
        DriverSession $session,
        TurnInput $input,
        DriverEventSink $events,
        CancellationSignal $cancellation,
    ): TurnResult {
        $state = WorkflowState::fromArray($session->state);
        $state->payload = (array) ($input->option('input')['payload'] ?? []);

        return $this->execute($session, $state, $input->runId, $events, $cancellation, $input->limits);
    }

    public function continueTurn(
        DriverSession $session,
        Continuation $continuation,
        DriverEventSink $events,
        CancellationSignal $cancellation,
    ): TurnResult {
        $state = WorkflowState::fromArray($session->state);
        $state->payload = (array) ($continuation->options['input']['payload'] ?? $state->payload);

        // Every resolved pause becomes an answer the body can read. A
        // rejection is still an answer: the workflow decides what to do with
        // it rather than the harness killing the run.
        foreach ($continuation->decisions as $decision) {
            $state->resumeInput[$decision->toolName] = [
                ...($decision->editedArguments ?? []),
                'approved' => $decision->approved,
                'reason' => $decision->reason,
            ];
        }

        $state->pause = null;

        return $this->execute($session, $state, $continuation->runId, $events, $cancellation, $continuation->limits);
    }

    /**
     * Drive the body once, from the top, and translate however it stopped.
     */
    protected function execute(
        DriverSession $session,
        WorkflowState $state,
        string $runId,
        DriverEventSink $events,
        CancellationSignal $cancellation,
        TurnLimits $limits = new TurnLimits,
    ): TurnResult {
        /** @var class-string<Workflow> $class */
        $class = $state->workflow;

        $run = Run::query()->with('session')->findOrFail($runId);

        /** @var Workflow $workflow */
        $workflow = app($class);

        $runtime = new WorkflowRuntime(
            run: $run,
            session: $run->session,
            state: $state,
            events: $events,
            cancellation: $cancellation,
            persist: $persist = function (WorkflowState $state) use ($session, $events): void {
                $events->checkpoint(new DriverCheckpoint(
                    $this->name(),
                    self::SCHEMA_VERSION,
                    $state->toArray(),
                    'workflow_step',
                    portable: true,
                    sessionId: $session->sessionId,
                ));
            },
            prompt: fn (string $prompt, string $agentClass, array $options) => $this->agents->prompt(
                $run, $state, $prompt, $agentClass, $options,
            ),
            limits: $limits,
        );

        $workflow->bind($runtime);

        try {
            $output = $workflow->handle($state->payload);
        } catch (WorkflowPaused $paused) {
            $state->pause = [
                'name' => $paused->name,
                'data' => $paused->data,
                'why' => $paused->why,
            ];

            $persist($state);

            $events->emitRaw('workflow.paused', [
                'workflow' => $class,
                'pause' => $paused->name,
                'why' => $paused->why,
            ]);

            return TurnResult::awaitingApproval(
                [new PendingApproval(
                    toolCallId: 'pause_'.$paused->name,
                    toolName: $paused->name,
                    arguments: $paused->data,
                    reason: $paused->why,
                )],
                text: $paused->why,
                session: $session->withState($state->toArray()),
            );
        } catch (WorkflowSliced $sliced) {
            // Everything completed so far is already persisted at its own
            // boundary; persisting once more records the state the next job
            // starts from. Not a failure: the run parks as suspended and the
            // coordinator queues the continuation itself.
            $persist($state);

            $events->emitRaw('workflow.sliced', [
                'workflow' => $class,
                'last_step' => $sliced->lastStep,
                'steps_this_slice' => $sliced->executed,
                'elapsed_seconds' => round($sliced->elapsedSeconds, 3),
            ]);

            return TurnResult::suspended(
                $session->withState($state->toArray()),
                text: $sliced->getMessage(),
            );
        } catch (AgentPaused $paused) {
            // The agent stopped, so the workflow stops with it, showing the
            // real tool call rather than a pause the workflow invented.
            $state->pause = [
                'name' => $this->agents->pauseKey($paused->agentClass),
                'agent' => $paused->agentClass,
                'agent_run_id' => $paused->agentRunId,
            ];

            $persist($state);

            $events->emitRaw('workflow.agent_paused', [
                'workflow' => $class,
                'agent' => $paused->agentClass,
                'agent_run_id' => $paused->agentRunId,
                'step' => $runtime->currentStep(),
            ]);

            return TurnResult::awaitingApproval(
                array_map(
                    fn (PendingApproval $approval): PendingApproval => new PendingApproval(
                        // Named for the agent, so the decision that resolves
                        // this reaches it on the next pass.
                        toolCallId: $approval->toolCallId,
                        toolName: $this->agents->pauseKey($paused->agentClass),
                        arguments: [
                            'tool' => $approval->toolName,
                            'arguments' => $approval->arguments,
                            'agent' => class_basename($paused->agentClass),
                        ],
                        reason: $approval->reason,
                    ),
                    $paused->approvals,
                ),
                text: sprintf('%s is waiting for approval.', class_basename($paused->agentClass)),
                session: $session->withState($state->toArray()),
            );
        } catch (WorkflowCancelled $cancelled) {
            $persist($state);

            return TurnResult::cancelled(
                $cancelled->why,
                session: $session->withState($state->toArray()),
            );
        } catch (Throwable $e) {
            // Persisted before the failure is reported, so a retry keeps the
            // steps that had already finished.
            $persist($state);

            $failure = new WorkflowFailed($class, $runtime->currentStep(), $e);

            $events->emitRaw('workflow.failed', [
                'workflow' => $class,
                'step' => $runtime->currentStep(),
                'exception' => $e::class,
            ]);

            return TurnResult::failed(
                new NormalizedFailure(
                    category: NormalizedFailure::classify($e),
                    // The harness-level message names the step, which is the
                    // one thing a stack trace alone will not tell you.
                    message: $failure->getMessage(),
                    exceptionClass: $e::class,
                    retryable: NormalizedFailure::classify($e)->isRetryable(),
                ),
                session: $session->withState($state->toArray()),
            );
        }

        $collected = $runtime->collect($workflow->produces());

        $events->emitRaw('workflow.completed', [
            'workflow' => $class,
            'steps' => $state->completedSteps(),
            'artifacts' => array_map(static fn ($a) => $a->id, $collected),
        ]);

        return TurnResult::completed(
            text: is_string($output) ? $output : null,
            structuredOutput: is_array($output) ? $output : ($output === null ? null : ['value' => $output]),
            session: $session->withState($state->toArray()),
        );
    }

    public function checkpoint(DriverSession $session): DriverCheckpoint
    {
        return new DriverCheckpoint(
            $this->name(),
            self::SCHEMA_VERSION,
            $session->state,
            'boundary',
            portable: true,
            sessionId: $session->sessionId,
        );
    }

    public function restore(DriverCheckpoint $checkpoint): DriverSession
    {
        return new DriverSession(
            (string) $checkpoint->sessionId,
            $this->name(),
            $checkpoint->payload,
        );
    }

    public function stop(DriverSession $session): DriverCheckpoint
    {
        return $this->checkpoint($session);
    }

    public function destroy(DriverSession $session): void
    {
        if (! (bool) config('clutch.workflows.discard_workspace', true)) {
            return;
        }

        // Scratch only. Artifacts are recorded separately and outlive the run.
        (new WorkflowWorkspace($session->sessionId))->discard();
    }
}
