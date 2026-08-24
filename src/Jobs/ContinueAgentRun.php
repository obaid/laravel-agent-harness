<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Jobs;

use AgentHarness\Laravel\Exceptions\LeaseUnavailable;
use AgentHarness\Laravel\Exceptions\RunNotFound;
use AgentHarness\Laravel\Runtime\RunCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Resumes a run that paused for approval, once every decision is recorded.
 */
class ContinueAgentRun implements ShouldQueue
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
            $coordinator->continueRun($this->runId);
        } catch (LeaseUnavailable) {
            $logger->info('Harness continuation skipped; the session lease is held elsewhere.', [
                'run_id' => $this->runId,
            ]);
        } catch (RunNotFound) {
            $logger->info('Harness run no longer exists; nothing to continue.', ['run_id' => $this->runId]);
        }
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['agent-harness', "run:{$this->runId}"];
    }
}
