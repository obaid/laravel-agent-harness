<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Jobs;

use AgentHarness\Laravel\Enums\ApprovalStatus;
use AgentHarness\Laravel\Models\Approval;
use AgentHarness\Laravel\Models\Artifact;
use AgentHarness\Laravel\Models\Checkpoint;
use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Models\RunEvent;
use AgentHarness\Laravel\Models\Session;
use AgentHarness\Laravel\Models\ToolExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes aged harness records in bounded batches.
 *
 * Pruning never removes data a non-terminal run still needs, the latest
 * resumable checkpoint of a resumable session, an unresolved approval, or
 * artifact metadata while the artifact is still user-accessible. The job is
 * safe to interrupt and restart.
 */
class PruneAgentHarnessRecords implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, int|null>  $retentionDays
     */
    public function __construct(
        public readonly array $retentionDays = [],
        public readonly int $batchSize = 1000,
        public readonly bool $deleteArtifactFiles = false,
    ) {}

    /**
     * @return array<string, int> counts removed, keyed by record type
     */
    public function handle(): array
    {
        return [
            'events' => $this->pruneEvents(),
            'checkpoints' => $this->pruneCheckpoints(),
            'tool_executions' => $this->pruneToolExecutions(),
            'artifacts' => $this->pruneArtifacts(),
            'run_payloads' => $this->pruneRunPayloads(),
            'sessions' => $this->pruneSoftDeletedSessions(),
        ];
    }

    protected function pruneEvents(): int
    {
        $days = $this->days('events');

        if ($days === null) {
            return 0;
        }

        // Only events belonging to runs that are themselves finished.
        return RunEvent::query()
            ->where('occurred_at', '<', now()->subDays($days))
            ->whereIn('run_id', Run::query()->terminal()->select('id'))
            ->limit($this->batchSize)
            ->delete();
    }

    protected function pruneCheckpoints(): int
    {
        $days = $this->days('checkpoints');

        if ($days === null) {
            return 0;
        }

        // The newest checkpoint of every session is the resume point and is
        // never a pruning candidate, whatever its age.
        $keep = Checkpoint::query()
            ->selectRaw('max(id) as id')
            ->groupBy('session_id')
            ->pluck('id');

        return Checkpoint::query()
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotIn('id', $keep)
            ->limit($this->batchSize)
            ->delete();
    }

    protected function pruneToolExecutions(): int
    {
        $days = $this->days('tool_executions');

        if ($days === null) {
            return 0;
        }

        return ToolExecution::query()
            ->where('created_at', '<', now()->subDays($days))
            ->whereIn('run_id', Run::query()->terminal()->select('id'))
            ->limit($this->batchSize)
            ->delete();
    }

    protected function pruneArtifacts(): int
    {
        $days = $this->days('artifacts');

        if ($days === null) {
            return 0;
        }

        $artifacts = Artifact::query()
            ->where('created_at', '<', now()->subDays($days))
            ->limit($this->batchSize)
            ->get();

        foreach ($artifacts as $artifact) {
            if ($this->deleteArtifactFiles) {
                Storage::disk($artifact->disk)->delete($artifact->path);
            }

            $artifact->delete();
        }

        return $artifacts->count();
    }

    /**
     * Blank out run inputs and outputs while keeping the audit skeleton.
     */
    protected function pruneRunPayloads(): int
    {
        $days = $this->days('run_payloads');

        if ($days === null) {
            return 0;
        }

        return Run::query()
            ->terminal()
            ->where('finished_at', '<', now()->subDays($days))
            ->whereNotNull('input')
            ->limit($this->batchSize)
            ->update(['input' => null, 'output_text' => null, 'structured_output' => null]);
    }

    protected function pruneSoftDeletedSessions(): int
    {
        $days = $this->days('sessions');

        if ($days === null) {
            return 0;
        }

        $sessions = Session::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($days))
            ->whereDoesntHave('approvals', fn ($query) => $query->where('status', ApprovalStatus::Pending->value))
            ->limit($this->batchSize)
            ->get();

        foreach ($sessions as $session) {
            $session->forceDelete();
        }

        return $sessions->count();
    }

    protected function days(string $key): ?int
    {
        $value = $this->retentionDays[$key]
            ?? config("agent-harness.retention.{$key}");

        return $value === null ? null : (int) $value;
    }
}
