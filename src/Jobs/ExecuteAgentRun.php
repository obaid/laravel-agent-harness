<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Jobs;

use AgentHarness\Laravel\Enums\FailureCategory;
use AgentHarness\Laravel\Exceptions\LeaseUnavailable;
use AgentHarness\Laravel\Exceptions\RunNotFound;
use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Runtime\RunCoordinator;
use AgentHarness\Laravel\ValueObjects\NormalizedFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes one queued run.
 *
 * The job carries only identifiers and the version it expects to find, and
 * reloads every mutable value. Duplicate delivery therefore exits safely after
 * observing that another worker holds the lease or that the state moved on.
 */
class ExecuteAgentRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $runId,
        public readonly ?int $expectedVersion = null,
    ) {}

    public function handle(RunCoordinator $coordinator, LoggerInterface $logger): void
    {
        try {
            $coordinator->executeRun($this->runId, streaming: false, expectedVersion: $this->expectedVersion);
        } catch (LeaseUnavailable) {
            // Another worker owns this session right now. Exiting is correct:
            // whoever holds the lease is already doing this work.
            $logger->info('Harness run skipped; the session lease is held elsewhere.', [
                'run_id' => $this->runId,
            ]);
        } catch (RunNotFound) {
            $logger->info('Harness run no longer exists; nothing to execute.', ['run_id' => $this->runId]);
        }
    }

    /**
     * Record a normalized failure when the job itself dies.
     */
    public function failed(Throwable $e): void
    {
        $run = Run::query()->with('session')->find($this->runId);

        if (! $run instanceof Run || $run->status->isTerminal()) {
            return;
        }

        app(RunCoordinator::class)->transitionRun(
            $run,
            \AgentHarness\Laravel\Enums\RunStatus::Failed,
            [
                'failure_category' => FailureCategory::WorkerLost,
                'failure_message' => 'The worker processing this run stopped unexpectedly.',
                'failure_exception_class' => $e::class,
                'finished_at' => now(),
            ],
            \AgentHarness\Laravel\Enums\EventType::RunFailed,
            NormalizedFailure::fromThrowable($e, FailureCategory::WorkerLost)->toArray(),
            clearActiveRun: true,
        );
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['agent-harness', "run:{$this->runId}"];
    }
}
