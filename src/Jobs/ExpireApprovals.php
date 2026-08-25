<?php

declare(strict_types=1);

namespace Clutch\Laravel\Jobs;

use Clutch\Laravel\Approvals\ApprovalBroker;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\RunCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Resolves approvals whose decision window elapsed.
 *
 * An expired approval reads as a rejection to the agent, and the run resumes so
 * it can react rather than hanging forever.
 */
class ExpireApprovals implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $limit = 200) {}

    public function handle(ApprovalBroker $approvals, RunCoordinator $coordinator): int
    {
        $runIds = Approval::query()
            ->expired()
            ->limit($this->limit)
            ->pluck('run_id')
            ->unique();

        $expired = 0;

        foreach ($runIds as $runId) {
            $run = Run::query()->with('session')->find($runId);

            if (! $run instanceof Run || $run->status->isTerminal()) {
                continue;
            }

            $expired += $approvals->expirePending($run);

            if ($run->status === RunStatus::AwaitingApproval && $approvals->allResolved($run)) {
                $coordinator->resumeAfterApproval($run->refresh());
            }
        }

        return $expired;
    }
}
