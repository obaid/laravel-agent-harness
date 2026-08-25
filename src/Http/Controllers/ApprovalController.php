<?php

declare(strict_types=1);

namespace Clutch\Laravel\Http\Controllers;

use Clutch\Laravel\Approvals\ApprovalBroker;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Runtime\RunCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records approval decisions and wakes the paused run.
 *
 * Decisions are idempotent: repeating one returns the existing result rather
 * than executing the tool twice.
 */
class ApprovalController
{
    public function __construct(
        protected ApprovalBroker $approvals,
        protected RunCoordinator $coordinator,
    ) {}

    public function index(Request $request, string $run): JsonResponse
    {
        $model = $this->run($request, $run);

        return new JsonResponse([
            'data' => $model->approvals()->get()->map(fn (Approval $approval): array => [
                'id' => $approval->id,
                'tool' => $approval->tool_name,
                'tool_call_id' => $approval->tool_call_id,
                'arguments' => $approval->arguments,
                'reason' => $approval->reason,
                'status' => $approval->status->value,
                'requested_at' => $approval->requested_at?->toISOString(),
                'expires_at' => $approval->expires_at?->toISOString(),
            ])->all(),
        ]);
    }

    public function approve(Request $request, string $run, string $approval): JsonResponse
    {
        return $this->decide($request, $run, $approval, approved: true);
    }

    public function reject(Request $request, string $run, string $approval): JsonResponse
    {
        return $this->decide($request, $run, $approval, approved: false);
    }

    protected function decide(Request $request, string $run, string $approvalId, bool $approved): JsonResponse
    {
        $model = $this->run($request, $run);

        $reason = $request->string('reason')->toString() ?: null;

        $record = $approved
            ? $this->approvals->approve($model, $approvalId, $reason, $request->user())
            : $this->approvals->reject($model, $approvalId, $reason, $request->user());

        // Only once every pending decision is in does the run go back to work.
        if ($this->approvals->allResolved($model)) {
            $this->coordinator->resumeAfterApproval($model->refresh());
        }

        return new JsonResponse([
            'approval_id' => $record->id,
            'status' => $record->status->value,
            'run_status' => $model->refresh()->status->value,
        ]);
    }

    protected function run(Request $request, string $run): \Clutch\Laravel\Models\Run
    {
        return \Clutch\Laravel\Models\Run::query()
            ->with('session')
            ->findOrFail($run)
            ->authorizeFor($request->user());
    }
}
