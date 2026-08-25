<?php

declare(strict_types=1);

namespace Clutch\Laravel\Runtime;

use Closure;
use Clutch\Laravel\Approvals\ApprovalBroker;
use Clutch\Laravel\Artifacts\ArtifactManager;
use Clutch\Laravel\Artifacts\ArtifactRegistrar;
use Clutch\Laravel\Budgets\BudgetManager;
use Clutch\Laravel\Checkpoints\CheckpointStore;
use Clutch\Laravel\Contracts\ClutchDriver;
use Clutch\Laravel\Data\Continuation;
use Clutch\Laravel\Data\DriverSession;
use Clutch\Laravel\Data\StartSession;
use Clutch\Laravel\Data\TurnInput;
use Clutch\Laravel\Data\TurnResult;
use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Enums\FailureCategory;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Enums\SessionStatus;
use Clutch\Laravel\Events\RunStatusChanged;
use Clutch\Laravel\Events\SessionStatusChanged;
use Clutch\Laravel\Exceptions\InvalidStateTransition;
use Clutch\Laravel\Exceptions\RunNotFound;
use Clutch\Laravel\Exceptions\SessionBusy;
use Clutch\Laravel\Jobs\ContinueAgentRun;
use Clutch\Laravel\Jobs\ExecuteAgentRun;
use Clutch\Laravel\Leases\LeaseManager;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Streaming\StreamedRun;
use Clutch\Laravel\Support\Id;
use Clutch\Laravel\ValueObjects\BudgetUsage;
use Clutch\Laravel\ValueObjects\NormalizedFailure;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The single orchestration authority for state transitions.
 *
 * No controller, queue job, event listener, or driver updates lifecycle status
 * directly. Everything routes through here so the transaction rules — state
 * and its event committing together, `active_run_id` clearing with the
 * terminal write, broadcasts deferred until after commit — hold everywhere.
 */
class RunCoordinator
{
    /**
     * Forwards lifecycle events to a live stream consumer while a run executes.
     *
     * Transitions are committed by `transitionRun()`, so without this the
     * run.started and terminal events would never reach a streaming client —
     * only the driver's own events would.
     *
     * @var (Closure(\Clutch\Laravel\Models\RunEvent): void)|null
     */
    protected ?Closure $streamListener = null;

    public function __construct(
        protected Connection $connection,
        protected DriverRegistry $drivers,
        protected EventStore $events,
        protected CheckpointStore $checkpoints,
        protected LeaseManager $leases,
        protected BudgetManager $budgets,
        protected ApprovalBroker $approvals,
        protected ArtifactManager $artifacts,
        protected Redactor $redactor,
        protected LoggerInterface $logger,
    ) {}

    // Session lifecycle --------------------------------------------------

    /**
     * Create a session and bring its driver online.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createSession(array $attributes): Session
    {
        $session = new Session([
            'id' => Id::session(),
            'status' => SessionStatus::Creating,
            ...$attributes,
        ]);

        $session->save();

        Event::dispatch(new SessionStatusChanged($session->id, null, SessionStatus::Creating));

        try {
            $driver = $this->drivers->driver($session->driver);

            $driverSession = $driver->start(new StartSession(
                sessionId: $session->id,
                agentClass: $session->agent_class,
                runtimeName: $session->runtime_name,
                permissionMode: $session->permission_mode,
                configuration: $session->configuration ?? [],
                participant: $session->participant,
                workspaceId: $session->workspace_id,
            ));

            $this->checkpoints->store($session, null, $driver->checkpoint($driverSession)->because('session_started'));

            $this->transitionSession($session, SessionStatus::Ready, [
                'conversation_id' => $driverSession->conversationId,
            ]);
        } catch (Throwable $e) {
            // Startup failed: mark the session failed rather than leaving it
            // stuck in `creating` where nothing will ever pick it up.
            $this->transitionSession($session, SessionStatus::Failed);

            $this->logger->error('Clutch session failed to start.', [
                'session_id' => $session->id,
                'driver' => $session->driver,
                'exception' => $e::class,
            ]);

            throw $e;
        }

        return $session->refresh();
    }

    /**
     * Stop a session, releasing driver resources but keeping its history.
     */
    public function stopSession(Session $session): Session
    {
        $this->transitionSession($session, SessionStatus::Stopping);

        $driver = $this->drivers->driver($session->driver);
        $driverSession = $this->restoreDriverSession($session, $driver);

        $checkpoint = $driver->stop($driverSession);

        $this->checkpoints->store($session, null, $checkpoint->because('session_stopped')->for($session->id, null));

        $this->transitionSession($session, SessionStatus::Stopped);

        return $session->refresh();
    }

    /**
     * Permanently destroy a session's runtime resources.
     */
    public function destroySession(Session $session): void
    {
        $driver = $this->drivers->driver($session->driver);

        try {
            $driver->destroy($this->restoreDriverSession($session, $driver));
        } catch (Throwable $e) {
            $this->logger->warning('Clutch driver failed to destroy a session cleanly.', [
                'session_id' => $session->id,
                'exception' => $e::class,
            ]);
        }

        $this->transitionSession($session, SessionStatus::Destroyed);

        $session->delete();
    }

    // Run creation -------------------------------------------------------

    /**
     * Create a run, atomically verifying the session has no active run.
     *
     * @param  array<int, mixed>  $attachments
     * @param  array<string, mixed>  $options
     *
     * @throws SessionBusy
     */
    public function createRun(Session $session, string $prompt, array $attachments = [], array $options = []): Run
    {
        return $this->connection->transaction(function () use ($session, $prompt, $attachments, $options): Run {
            /** @var Session $locked */
            $locked = Session::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->active_run_id !== null) {
                throw SessionBusy::withActiveRun($locked->id, $locked->active_run_id);
            }

            $run = new Run([
                'id' => Id::run(),
                'session_id' => $locked->id,
                'attempt' => 1,
                'status' => RunStatus::Created,
                'input_type' => 'prompt',
                'input' => ['prompt' => $prompt, 'attachments' => $attachments],
                'idempotency_key' => $options['idempotency_key'] ?? null,
                'budget' => $options['budget'] ?? null,
                'metadata' => $options['metadata'] ?? null,
                'usage' => (new BudgetUsage)->toArray(),
                'last_event_sequence' => 0,
            ]);

            $run->save();

            $locked->forceFill([
                'active_run_id' => $run->id,
                'version' => $locked->version + 1,
            ])->save();

            $this->events->append($run, EventType::RunCreated, [
                'prompt' => $this->redactor->redact(['prompt' => $prompt])['prompt'],
                'attempt' => 1,
                'driver' => $locked->driver,
                'agent' => $locked->agent_class,
            ]);

            Event::dispatch(new RunStatusChanged($locked->id, $run->id, null, RunStatus::Created, $locked->driver));

            return $run;
        });
    }

    /**
     * Create a run and hand it to the queue.
     *
     * @param  array<int, mixed>  $attachments
     * @param  array<string, mixed>  $options
     */
    public function queueRun(Session $session, string $prompt, array $attachments = [], array $options = []): Run
    {
        $run = $this->createRun($session, $prompt, $attachments, $options);

        $this->transitionRun($run, RunStatus::Queued, ['queued_at' => Carbon::now()], EventType::RunQueued, [
            'connection' => $session->queue_connection,
            'queue' => $session->queue,
        ]);

        // Dispatched after commit so a worker can never pick up a run whose
        // `queued` state is not yet readable.
        $this->connection->afterCommit(function () use ($session, $run): void {
            ExecuteAgentRun::dispatch($run->id, $run->version)
                ->onConnection($session->queue_connection ?? config('clutch.queue.connection'))
                ->onQueue($session->queue ?? config('clutch.queue.queue'));
        });

        return $run->refresh();
    }

    /**
     * Create and execute a run synchronously, returning its terminal result.
     *
     * @param  array<int, mixed>  $attachments
     * @param  array<string, mixed>  $options
     */
    public function promptNow(Session $session, string $prompt, array $attachments = [], array $options = []): ClutchResult
    {
        $run = $this->createRun($session, $prompt, $attachments, $options);

        $run = $this->executeRun($run->id, streaming: false);

        return ClutchResult::fromRun($run);
    }

    /**
     * Create a run and stream its normalized events as they are recorded.
     *
     * @param  array<int, mixed>  $attachments
     * @param  array<string, mixed>  $options
     */
    public function streamRun(Session $session, string $prompt, array $attachments = [], array $options = []): StreamedRun
    {
        $run = $this->createRun($session, $prompt, $attachments, $options);

        return new StreamedRun($run, function (Closure $emit) use ($run): Run {
            return $this->executeRun($run->id, streaming: true, onEvent: $emit);
        });
    }

    // Run execution ------------------------------------------------------

    /**
     * Execute a run under the session lease.
     *
     * Safe to call more than once for the same run: duplicate delivery either
     * fails to take the lease or finds the run no longer executable and exits
     * without mutating newer state.
     *
     * @param  (Closure(\Clutch\Laravel\Models\RunEvent): void)|null  $onEvent
     */
    public function executeRun(string $runId, bool $streaming = false, ?Closure $onEvent = null, ?int $expectedVersion = null): Run
    {
        $run = Run::query()->with('session')->find($runId) ?? throw RunNotFound::withId($runId);

        return $this->leases->withLease($run->session_id, function () use ($run, $streaming, $onEvent, $expectedVersion): Run {
            // Reload with fresh state now that we hold the lease; anything read
            // before this point may have been written by the previous holder.
            $run = $run->fresh(['session']) ?? throw RunNotFound::withId($run->id);
            $session = $run->session;

            if (! $this->isExecutable($run, $expectedVersion)) {
                return $run;
            }

            return $this->runTurnFor($session, $run, $streaming, $onEvent, continuation: null);
        });
    }

    /**
     * Resume a paused run once its approvals are resolved.
     *
     * @param  (Closure(\Clutch\Laravel\Models\RunEvent): void)|null  $onEvent
     */
    public function continueRun(string $runId, bool $streaming = false, ?Closure $onEvent = null): Run
    {
        $run = Run::query()->with('session')->find($runId) ?? throw RunNotFound::withId($runId);

        return $this->leases->withLease($run->session_id, function () use ($run, $streaming, $onEvent): Run {
            $run = $run->fresh(['session']) ?? throw RunNotFound::withId($run->id);
            $session = $run->session;

            if ($run->status->isTerminal()) {
                return $run;
            }

            $continuation = new Continuation(
                runId: $run->id,
                decisions: $this->approvals->decisionsFor($run),
                streaming: $streaming,
            );

            return $this->runTurnFor($session, $run, $streaming, $onEvent, $continuation);
        });
    }

    /**
     * The shared execution path for a fresh turn and for a continuation.
     *
     * @param  (Closure(\Clutch\Laravel\Models\RunEvent): void)|null  $onEvent
     */
    protected function runTurnFor(
        Session $session,
        Run $run,
        bool $streaming,
        ?Closure $onEvent,
        ?Continuation $continuation,
    ): Run {
        try {
            return $this->performTurn($session, $run, $streaming, $onEvent, $continuation);
        } finally {
            // Never let one turn's stream consumer observe a later run's events.
            $this->streamListener = null;
        }
    }

    /**
     * @param  (Closure(\Clutch\Laravel\Models\RunEvent): void)|null  $onEvent
     */
    protected function performTurn(
        Session $session,
        Run $run,
        bool $streaming,
        ?Closure $onEvent,
        ?Continuation $continuation,
    ): Run {
        $driver = $this->drivers->driver($session->driver);

        if ($streaming) {
            $this->drivers->requireCapability($driver, 'streaming');
        }

        if ($continuation instanceof Continuation) {
            $this->drivers->requireCapability($driver, 'approvals');
        }

        $budget = $this->budgets->effectiveBudget($session, $run);
        $startingUsage = $run->usage();

        // A budget already exhausted by earlier attempts must not start a new one.
        if (($limit = $this->budgets->exhaustedLimit($budget, $startingUsage)) !== null) {
            $this->finalizeBudgetExceeded($session, $run, $this->budgets->describeExhaustion($limit, $budget, $startingUsage), $startingUsage);

            return $run->refresh();
        }

        // The listener is installed before the first transition so a streaming
        // client sees run.started and the terminal event, not only the driver's
        // own events.
        $this->streamListener = $onEvent;

        $recorder = new EventRecorder($session, $run, $this->events, $this->checkpoints, $this->redactor);

        if ($onEvent instanceof Closure) {
            $recorder->listen($onEvent);
        }

        $this->transitionRun($run, RunStatus::Running, [
            'started_at' => $run->started_at ?? Carbon::now(),
            'heartbeat_at' => Carbon::now(),
        ], EventType::RunStarted, [
            'attempt' => (int) $run->attempt,
            'driver' => $driver->name(),
            'streaming' => $streaming,
            'continuation' => $continuation instanceof Continuation,
        ]);

        $this->activate($session);

        $cancellation = $this->cancellationSignalFor($run);

        $context = new RunContext(
            session: $session,
            run: $run,
            artifacts: new ArtifactRegistrar($run, $this->artifacts),
            cancellation: $cancellation,
            logger: $this->logger,
            redactor: $this->redactor,
        );

        $startedAt = microtime(true);

        try {
            $driverSession = $this->restoreDriverSession($session, $driver);

            $result = $context->scope(function () use (
                $driver, $driverSession, $run, $session, $recorder, $cancellation, $continuation, $streaming, $budget
            ): TurnResult {
                if ($continuation instanceof Continuation) {
                    return $driver->continueTurn(
                        $driverSession,
                        new Continuation(
                            $continuation->runId,
                            $continuation->decisions,
                            $streaming,
                            ['budget' => $budget, 'participant' => $session->participant],
                        ),
                        $recorder,
                        $cancellation,
                    );
                }

                return $driver->runTurn(
                    $driverSession,
                    new TurnInput(
                        runId: $run->id,
                        prompt: $run->promptText(),
                        attachments: $run->input['attachments'] ?? [],
                        permissionMode: $session->permission_mode,
                        budget: $budget,
                        streaming: $streaming,
                        options: ['participant' => $session->participant],
                    ),
                    $recorder,
                    $cancellation,
                );
            });
        } catch (Throwable $e) {
            $this->finalizeFailure($session, $run, NormalizedFailure::fromThrowable($e), $this->elapsed($startingUsage, $startedAt));

            $this->logger->error('Clutch run failed.', [
                'session_id' => $session->id,
                'run_id' => $run->id,
                'driver' => $driver->name(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $run->refresh();
        }

        return $this->finalize($session, $run, $driver, $result, $startingUsage, $startedAt, $budget);
    }

    /**
     * Commit whatever outcome the driver reported.
     *
     * @param  \Clutch\Laravel\ValueObjects\RunBudget  $budget
     */
    protected function finalize(
        Session $session,
        Run $run,
        ClutchDriver $driver,
        TurnResult $result,
        BudgetUsage $startingUsage,
        float $startedAt,
        $budget,
    ): Run {
        $usage = $startingUsage->add($result->usage)->withElapsedSeconds(
            $this->elapsed($startingUsage, $startedAt)->elapsedSeconds
        );

        // Persist the between-turn checkpoint before any terminal write, so a
        // later run can always restore the conversation this turn produced.
        if ($result->session instanceof DriverSession) {
            // A paused turn was already checkpointed by the driver at the pause
            // itself; that is the resume point, so it is not overwritten here.
            if (! $result->isAwaitingApproval()) {
                $this->checkpoints->store(
                    $session,
                    $run,
                    $driver->checkpoint($result->session)->because('turn_boundary')->for($session->id, $run->id),
                );
            }

            if ($result->session->conversationId !== null) {
                $session->forceFill(['conversation_id' => $result->session->conversationId])->save();
            }
        }

        $this->events->append($run, EventType::UsageUpdated, ['usage' => $usage->toArray()]);

        return match (true) {
            $result->isAwaitingApproval() => $this->finalizeAwaitingApproval($session, $run, $result, $usage),
            $result->exceededBudget() => $this->finalizeBudgetExceeded(
                $session, $run,
                $this->budgets->describeExhaustion(
                    $this->budgets->exhaustedLimit($budget, $usage) ?? 'max_steps',
                    $budget,
                    $usage,
                ),
                $usage,
            ),
            $result->isCancelled() => $this->finalizeCancelled($session, $run, $result, $usage),
            $result->isFailed() => $this->finalizeFailure(
                $session, $run,
                $result->failure ?? new NormalizedFailure(FailureCategory::DriverError, 'The driver reported a failure without detail.'),
                $usage,
            ),
            default => $this->finalizeCompleted($session, $run, $result, $usage),
        };
    }

    protected function finalizeCompleted(Session $session, Run $run, TurnResult $result, BudgetUsage $usage): Run
    {
        $this->transitionRun($run, RunStatus::Completed, [
            'output_text' => $result->text,
            'structured_output' => $result->structuredOutput,
            'usage' => $usage->toArray(),
            'cost_usd' => $usage->costUsd,
            'finished_at' => Carbon::now(),
        ], EventType::RunCompleted, [
            'text' => $result->text,
            'structured' => $result->structuredOutput,
            'usage' => $usage->toArray(),
        ], clearActiveRun: true);

        $this->transitionSession($session, SessionStatus::Ready);

        return $run->refresh();
    }

    protected function finalizeAwaitingApproval(Session $session, Run $run, TurnResult $result, BudgetUsage $usage): Run
    {
        // The approvals and their events are recorded before the pause is
        // committed, so a decision endpoint always finds something to resolve.
        $this->approvals->request($run, $result->pendingApprovals);

        $this->transitionRun($run, RunStatus::AwaitingApproval, [
            'output_text' => $result->text ?? $run->output_text,
            'usage' => $usage->toArray(),
            'cost_usd' => $usage->costUsd,
        ], EventType::RunAwaitingApproval, [
            'approvals' => array_map(
                fn ($approval): array => [
                    'tool_call_id' => $approval->toolCallId,
                    'tool' => $approval->toolName,
                    'reason' => $approval->reason,
                ],
                $result->pendingApprovals,
            ),
        ]);

        $this->transitionSession($session, SessionStatus::AwaitingApproval);

        return $run->refresh();
    }

    protected function finalizeCancelled(Session $session, Run $run, TurnResult $result, BudgetUsage $usage): Run
    {
        $this->approvals->cancelPending($run);

        $this->transitionRun($run, RunStatus::Cancelled, [
            'output_text' => $result->text ?? $run->output_text,
            'usage' => $usage->toArray(),
            'cost_usd' => $usage->costUsd,
            'failure_category' => FailureCategory::Cancelled,
            'failure_message' => $run->cancellation_reason ?? 'The run was cancelled.',
            'finished_at' => Carbon::now(),
        ], EventType::RunCancelled, [
            'reason' => $run->cancellation_reason,
            'usage' => $usage->toArray(),
        ], clearActiveRun: true);

        $this->transitionSession($session, SessionStatus::Ready);

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $exhaustion
     */
    protected function finalizeBudgetExceeded(Session $session, Run $run, array $exhaustion, BudgetUsage $usage): Run
    {
        $this->approvals->cancelPending($run);

        $this->transitionRun($run, RunStatus::BudgetExceeded, [
            'usage' => $usage->toArray(),
            'cost_usd' => $usage->costUsd,
            'failure_category' => FailureCategory::BudgetExceeded,
            'failure_message' => 'The run stopped because its ['.($exhaustion['limit'] ?? 'budget').'] limit was reached.',
            'finished_at' => Carbon::now(),
        ], EventType::RunBudgetExceeded, $exhaustion, clearActiveRun: true);

        $this->transitionSession($session, SessionStatus::Ready);

        return $run->refresh();
    }

    protected function finalizeFailure(Session $session, Run $run, NormalizedFailure $failure, BudgetUsage $usage): Run
    {
        $this->approvals->cancelPending($run);

        $this->transitionRun($run, RunStatus::Failed, [
            'usage' => $usage->toArray(),
            'cost_usd' => $usage->costUsd,
            'failure_category' => $failure->category,
            'failure_message' => $failure->message,
            'failure_exception_class' => $failure->exceptionClass,
            'finished_at' => Carbon::now(),
        ], EventType::RunFailed, $failure->toArray(), clearActiveRun: true);

        $this->transitionSession($session, SessionStatus::Ready);

        return $run->refresh();
    }

    // Cancellation and retry ---------------------------------------------

    /**
     * Request cooperative cancellation of a run.
     *
     * The request is durable immediately; a worker observes it at its next safe
     * boundary and stops before starting anything new.
     */
    public function requestCancellation(Run $run, ?string $reason = null): Run
    {
        return $this->connection->transaction(function () use ($run, $reason): Run {
            /** @var Run $locked */
            $locked = Run::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            $locked->forceFill([
                'cancellation_requested_at' => $locked->cancellation_requested_at ?? Carbon::now(),
                'cancellation_reason' => $reason ?? $locked->cancellation_reason,
                'version' => $locked->version + 1,
            ])->save();

            // A run that is not currently executing has nobody to observe the
            // signal, so the coordinator closes it out here.
            if (in_array($locked->status, [RunStatus::Created, RunStatus::Queued, RunStatus::AwaitingApproval], true)) {
                $session = $locked->session;

                $this->approvals->cancelPending($locked);

                $this->transitionRun($locked, RunStatus::Cancelled, [
                    'failure_category' => FailureCategory::Cancelled,
                    'failure_message' => $reason ?? 'The run was cancelled before it started.',
                    'finished_at' => Carbon::now(),
                ], EventType::RunCancelled, ['reason' => $reason], clearActiveRun: true);

                if ($session instanceof Session) {
                    $this->transitionSession($session, SessionStatus::Ready);
                }

                return $locked->refresh();
            }

            $this->transitionRun($locked, RunStatus::Cancelling, [], null, []);

            return $locked->refresh();
        });
    }

    /**
     * Create a fresh attempt of a terminal run.
     *
     * The original terminal record is never reopened.
     */
    public function retryRun(Run $run, bool $resetBudget = false): Run
    {
        $session = $run->session;

        return $this->connection->transaction(function () use ($run, $session, $resetBudget): Run {
            /** @var Session $locked */
            $locked = Session::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->active_run_id !== null) {
                throw SessionBusy::withActiveRun($locked->id, $locked->active_run_id);
            }

            $retry = new Run([
                'id' => Id::run(),
                'session_id' => $locked->id,
                'attempt' => (int) $run->attempt + 1,
                'retry_of_run_id' => $run->id,
                'status' => RunStatus::Created,
                'input_type' => $run->input_type,
                'input' => $run->input,
                'budget' => $run->budget,
                'metadata' => $run->metadata,
                'usage' => $this->budgets->startingUsageFor($run, $resetBudget)->toArray(),
                'last_event_sequence' => 0,
            ]);

            $retry->save();

            $locked->forceFill([
                'active_run_id' => $retry->id,
                'version' => $locked->version + 1,
            ])->save();

            $this->events->append($retry, EventType::RunCreated, [
                'attempt' => $retry->attempt,
                'retry_of_run_id' => $run->id,
                'budget_reset' => $resetBudget,
            ]);

            $this->transitionRun($retry, RunStatus::Queued, ['queued_at' => Carbon::now()], EventType::RunQueued, []);

            $this->connection->afterCommit(function () use ($locked, $retry): void {
                ExecuteAgentRun::dispatch($retry->id, $retry->version)
                    ->onConnection($locked->queue_connection ?? config('clutch.queue.connection'))
                    ->onQueue($locked->queue ?? config('clutch.queue.queue'));
            });

            return $retry;
        });
    }

    /**
     * Re-queue a paused run whose approvals are now all resolved.
     */
    public function resumeAfterApproval(Run $run): Run
    {
        if ($run->status !== RunStatus::AwaitingApproval || ! $this->approvals->allResolved($run)) {
            return $run;
        }

        $session = $run->session;

        $this->transitionRun($run, RunStatus::Queued, ['queued_at' => Carbon::now()], EventType::RunQueued, [
            'reason' => 'approval_resolved',
        ]);

        $this->connection->afterCommit(function () use ($session, $run): void {
            ContinueAgentRun::dispatch($run->id, $run->version)
                ->onConnection($session->queue_connection ?? config('clutch.queue.connection'))
                ->onQueue($session->queue ?? config('clutch.queue.queue'));
        });

        return $run->refresh();
    }

    // Transitions --------------------------------------------------------

    /**
     * Commit a run status change together with its lifecycle event.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidStateTransition
     */
    public function transitionRun(
        Run $run,
        RunStatus $to,
        array $attributes = [],
        ?EventType $event = null,
        array $payload = [],
        bool $clearActiveRun = false,
    ): Run {
        $from = $run->status;
        $recorded = null;

        $this->connection->transaction(function () use ($run, $to, $attributes, $event, $payload, $clearActiveRun, &$recorded): void {
            /** @var Run $locked */
            $locked = Run::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $to && ! $locked->status->canTransitionTo($to)) {
                throw InvalidStateTransition::forRun($run->id, $locked->status->value, $to->value);
            }

            $locked->forceFill([
                'status' => $to,
                'version' => $locked->version + 1,
                ...$attributes,
            ])->save();

            // Raw attributes, not forceFill: the locked row already holds
            // encrypted values, and re-casting them would encrypt twice.
            $run->setRawAttributes($locked->getAttributes(), sync: true);

            if ($event instanceof EventType) {
                $recorded = $this->events->append($run, $event, $payload);
            }

            // Freeing the session's active slot happens in the same transaction
            // as the terminal write, so the slot is never observably occupied
            // by a finished run.
            if ($clearActiveRun) {
                Session::query()
                    ->whereKey($locked->session_id)
                    ->where('active_run_id', $locked->id)
                    ->update(['active_run_id' => null]);
            }
        });

        if ($recorded instanceof \Clutch\Laravel\Models\RunEvent && $this->streamListener instanceof Closure) {
            ($this->streamListener)($recorded);
        }

        Event::dispatch(new RunStatusChanged(
            $run->session_id, $run->id, $from, $to, $run->session->driver,
        ));

        return $run;
    }

    /**
     * Commit a session status change.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionSession(Session $session, SessionStatus $to, array $attributes = []): Session
    {
        $from = $session->status;

        if ($from === $to && $attributes === []) {
            return $session;
        }

        $this->connection->transaction(function () use ($session, $to, $attributes): void {
            /** @var Session $locked */
            $locked = Session::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $to && ! $locked->status->canTransitionTo($to)) {
                throw InvalidStateTransition::forSession($session->id, $locked->status->value, $to->value);
            }

            $locked->forceFill([
                'status' => $to,
                'version' => $locked->version + 1,
                ...$attributes,
            ])->save();

            $session->setRawAttributes($locked->getAttributes(), sync: true);
        });

        Event::dispatch(new SessionStatusChanged($session->id, $from, $to));

        return $session;
    }

    // Helpers ------------------------------------------------------------

    /**
     * Bring a session to `running`, reactivating it first if it was stopped.
     *
     * The documented path is stopped -> ready -> running; skipping `ready`
     * would leave the state machine's diagram lying about what happens.
     */
    protected function activate(Session $session): void
    {
        if ($session->status === SessionStatus::Stopped) {
            $this->transitionSession($session, SessionStatus::Ready);
        }

        $this->transitionSession($session, SessionStatus::Running);
    }

    /**
     * Rebuild the driver's session handle from the last stored checkpoint.
     */
    protected function restoreDriverSession(Session $session, ClutchDriver $driver): DriverSession
    {
        $checkpoint = $this->checkpoints->latestForSession($session->id);

        if ($checkpoint === null) {
            // No checkpoint yet — start the driver fresh rather than failing,
            // which also covers sessions created before a driver upgrade.
            return $driver->start(new StartSession(
                sessionId: $session->id,
                agentClass: $session->agent_class,
                runtimeName: $session->runtime_name,
                permissionMode: $session->permission_mode,
                configuration: $session->configuration ?? [],
                participant: $session->participant,
                workspaceId: $session->workspace_id,
            ));
        }

        return $driver->restore($checkpoint->toDriverCheckpoint());
    }

    /**
     * Build a signal that re-reads durable cancellation state as the turn runs.
     */
    protected function cancellationSignalFor(Run $run): CancellationSignal
    {
        $signal = new CancellationSignal(function () use ($run): bool {
            return (bool) Run::query()
                ->whereKey($run->id)
                ->whereNotNull('cancellation_requested_at')
                ->exists();
        });

        if ($run->isCancellationRequested()) {
            $signal->cancel($run->cancellation_reason);
        }

        return $signal;
    }

    /**
     * Decide whether a delivered job should actually run.
     *
     * A duplicate or stale delivery exits here rather than mutating state that
     * a newer attempt already owns.
     */
    protected function isExecutable(Run $run, ?int $expectedVersion): bool
    {
        if ($run->status->isTerminal()) {
            $this->logger->info('Clutch skipped a run that already reached a terminal state.', [
                'run_id' => $run->id,
                'status' => $run->status->value,
            ]);

            return false;
        }

        if ($expectedVersion !== null && $run->version > $expectedVersion + 1) {
            $this->logger->info('Clutch skipped a stale run delivery.', [
                'run_id' => $run->id,
                'expected_version' => $expectedVersion,
                'actual_version' => $run->version,
            ]);

            return false;
        }

        return true;
    }

    protected function elapsed(BudgetUsage $usage, float $startedAt): BudgetUsage
    {
        return $usage->withElapsedSeconds(
            $usage->elapsedSeconds + (int) round(microtime(true) - $startedAt)
        );
    }
}
