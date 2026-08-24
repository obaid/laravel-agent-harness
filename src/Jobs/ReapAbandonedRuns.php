<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Jobs;

use AgentHarness\Laravel\Enums\EventType;
use AgentHarness\Laravel\Enums\FailureCategory;
use AgentHarness\Laravel\Enums\RunStatus;
use AgentHarness\Laravel\Leases\LeaseManager;
use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Runtime\RunCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Detects runs whose worker disappeared and either retries or fails them.
 *
 * A run is abandoned when it still claims to be running, its heartbeat has
 * gone stale, and nobody holds its session lease.
 */
class ReapAbandonedRuns implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $staleAfterSeconds = 300,
        public readonly bool $retry = true,
    ) {}

    public function handle(RunCoordinator $coordinator, LeaseManager $leases, LoggerInterface $logger): void
    {
        $cutoff = now()->subSeconds($this->staleAfterSeconds);

        $abandoned = Run::query()
            ->with('session')
            ->whereIn('status', [RunStatus::Running->value, RunStatus::Cancelling->value])
            ->where(fn ($query) => $query
                ->where('heartbeat_at', '<', $cutoff)
                ->orWhereNull('heartbeat_at'))
            ->where('started_at', '<', $cutoff)
            ->limit(100)
            ->get();

        foreach ($abandoned as $run) {
            // Taking the lease is both the liveness check and the mutual
            // exclusion: a worker that is merely slow still holds it, and
            // holding it here stops that worker's replacement racing us.
            $lease = $leases->acquire($run->session_id);

            if (! $lease instanceof \AgentHarness\Laravel\Leases\Lease) {
                continue;
            }

            try {
                // Re-read now that we hold the lease; the state may have moved.
                $run = $run->fresh();

                if ($run === null || $run->status->isTerminal()) {
                    continue;
                }

                $logger->warning('Harness reaped an abandoned run.', [
                    'run_id' => $run->id,
                    'session_id' => $run->session_id,
                ]);

                $failure = [
                    'category' => FailureCategory::WorkerLost->value,
                    'message' => 'The worker processing this run stopped unexpectedly.',
                    'retryable' => true,
                ];

                $coordinator->transitionRun($run, RunStatus::Failed, [
                    'failure_category' => FailureCategory::WorkerLost,
                    'failure_message' => $failure['message'],
                    'finished_at' => now(),
                ], EventType::RunFailed, $failure, clearActiveRun: true);
            } finally {
                $lease->release();
            }

            if ($this->retry) {
                // Resumes from the run's last safe checkpoint as a new attempt.
                $coordinator->retryRun($run->refresh());
            }
        }
    }
}
