<?php

declare(strict_types=1);

namespace Clutch\Laravel\Approvals;

use Clutch\Laravel\Data\ApprovalDecision;
use Clutch\Laravel\Data\PendingApproval;
use Clutch\Laravel\Enums\ApprovalStatus;
use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Events\ApprovalRequested;
use Clutch\Laravel\Events\ApprovalResolved;
use Clutch\Laravel\Exceptions\ApprovalAlreadyResolved;
use Clutch\Laravel\Exceptions\ApprovalNotFound;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\EventStore;
use Clutch\Laravel\Support\Id;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/**
 * Creates approval records, resolves decisions idempotently, and wakes the run.
 *
 * A decision and its `approval.resolved` event commit together, and the
 * continuation job is dispatched only after that commit — so an approval can
 * never be observed as resolved by a worker that then finds no record of it.
 */
class ApprovalBroker
{
    public function __construct(
        protected Connection $connection,
        protected EventStore $events,
        protected ?int $expiresAfterSeconds = null,
    ) {}

    /**
     * Record the approvals a driver paused on.
     *
     * Re-recording the same tool call is a no-op, which is what makes a
     * duplicated worker delivery safe.
     *
     * @param  array<int, PendingApproval>  $pending
     * @return Collection<int, Approval>
     */
    public function request(Run $run, array $pending): Collection
    {
        $approvals = new Collection;

        foreach ($pending as $item) {
            $approval = $this->findByToolCall($run->id, $item->toolCallId) ?? $this->create($run, $item);

            $approvals->push($approval);
        }

        foreach ($approvals as $approval) {
            if ($approval->wasRecentlyCreated) {
                Event::dispatch(new ApprovalRequested($approval));
            }
        }

        return $approvals;
    }

    /**
     * Approve one pending tool call.
     */
    public function approve(Run $run, string $approvalId, ?string $reason = null, ?object $actor = null): Approval
    {
        return $this->resolve($run, $approvalId, ApprovalStatus::Approved, $reason, $actor);
    }

    /**
     * Reject one pending tool call.
     */
    public function reject(Run $run, string $approvalId, ?string $reason = null, ?object $actor = null): Approval
    {
        return $this->resolve($run, $approvalId, ApprovalStatus::Rejected, $reason, $actor);
    }

    /**
     * Approve a call with edited arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function approveWithArguments(Run $run, string $approvalId, array $arguments, ?string $reason = null, ?object $actor = null): Approval
    {
        return $this->resolve($run, $approvalId, ApprovalStatus::Approved, $reason, $actor, $arguments);
    }

    /**
     * Resolve a decision exactly once.
     *
     * Repeating the same decision returns the existing record. Attempting to
     * reverse a resolved decision raises.
     *
     * @param  array<string, mixed>|null  $editedArguments
     *
     * @throws ApprovalNotFound|ApprovalAlreadyResolved
     */
    public function resolve(
        Run $run,
        string $approvalId,
        ApprovalStatus $status,
        ?string $reason = null,
        ?object $actor = null,
        ?array $editedArguments = null,
    ): Approval {
        [$approval, $changed] = $this->connection->transaction(function () use (
            $run, $approvalId, $status, $reason, $actor, $editedArguments
        ): array {
            /** @var Approval|null $approval */
            $approval = Approval::query()
                ->where('id', $approvalId)
                ->where('run_id', $run->id)
                ->lockForUpdate()
                ->first();

            if (! $approval instanceof Approval) {
                throw ApprovalNotFound::withId($approvalId);
            }

            if ($approval->status->isResolved()) {
                // Repeating an identical decision is idempotent; reversing one is not.
                if ($approval->status === $status) {
                    return [$approval, false];
                }

                throw ApprovalAlreadyResolved::with($approvalId, $approval->status->value);
            }

            $approval->forceFill([
                'status' => $status,
                'resolved_at' => Carbon::now(),
                'resolved_by_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
                'resolved_by_id' => $actor instanceof Model ? (string) $actor->getKey() : null,
                'decision_reason' => $reason,
                'edited_arguments' => $editedArguments,
                'version' => $approval->version + 1,
            ])->save();

            $this->events->append($run, EventType::ApprovalResolved, [
                'approval_id' => $approval->id,
                'tool_call_id' => $approval->tool_call_id,
                'tool' => $approval->tool_name,
                'status' => $status->value,
                'reason' => $reason,
                'resolved_by' => $actor instanceof Model
                    ? ['type' => $actor->getMorphClass(), 'id' => (string) $actor->getKey()]
                    : null,
            ]);

            return [$approval, true];
        });

        if ($changed) {
            Event::dispatch(new ApprovalResolved($approval, $status === ApprovalStatus::Approved));
        }

        return $approval;
    }

    /**
     * Expire approvals that outlived their window.
     *
     * @return int the number expired
     */
    public function expirePending(Run $run): int
    {
        $expired = 0;

        foreach ($run->approvals()->expired()->get() as $approval) {
            $approval->forceFill([
                'status' => ApprovalStatus::Expired,
                'resolved_at' => Carbon::now(),
                'version' => $approval->version + 1,
            ])->save();

            $this->events->append($run, EventType::ApprovalResolved, [
                'approval_id' => $approval->id,
                'tool_call_id' => $approval->tool_call_id,
                'tool' => $approval->tool_name,
                'status' => ApprovalStatus::Expired->value,
                'reason' => 'The approval window elapsed before a decision was recorded.',
            ]);

            $expired++;
        }

        return $expired;
    }

    /**
     * Determine whether every approval on a run has been decided.
     */
    public function allResolved(Run $run): bool
    {
        return ! $run->approvals()->pending()->exists();
    }

    /**
     * Build the driver-facing decisions for a paused run.
     *
     * @return array<int, ApprovalDecision>
     */
    public function decisionsFor(Run $run): array
    {
        return $run->approvals()
            ->whereIn('status', [
                ApprovalStatus::Approved->value,
                ApprovalStatus::Rejected->value,
                ApprovalStatus::Expired->value,
            ])
            ->get()
            ->map(fn (Approval $approval): ApprovalDecision => new ApprovalDecision(
                approvalId: $approval->id,
                toolCallId: $approval->tool_call_id,
                toolName: $approval->tool_name,
                approved: $approval->isApproved(),
                reason: $approval->decision_reason,
                editedArguments: $approval->edited_arguments,
            ))
            ->all();
    }

    /**
     * Cancel every pending approval on a run, e.g. when the run is cancelled.
     */
    public function cancelPending(Run $run): void
    {
        $run->approvals()->pending()->update([
            'status' => ApprovalStatus::Cancelled->value,
            'resolved_at' => Carbon::now(),
        ]);
    }

    public function findByToolCall(string $runId, string $toolCallId): ?Approval
    {
        return Approval::query()
            ->where('run_id', $runId)
            ->where('tool_call_id', $toolCallId)
            ->first();
    }

    protected function create(Run $run, PendingApproval $pending): Approval
    {
        try {
            $approval = Approval::query()->create([
                'id' => Id::approval(),
                'session_id' => $run->session_id,
                'run_id' => $run->id,
                'tool_call_id' => $pending->toolCallId,
                'tool_name' => $pending->toolName,
                'arguments' => $pending->arguments,
                'reason' => $pending->reason,
                'status' => ApprovalStatus::Pending,
                'requested_at' => Carbon::now(),
                'expires_at' => $this->expiresAfterSeconds
                    ? Carbon::now()->addSeconds($this->expiresAfterSeconds)
                    : null,
            ]);
        } catch (QueryException) {
            // The unique key on (run_id, tool_call_id) already holds a record;
            // a concurrent worker recorded the same pause.
            return $this->findByToolCall($run->id, $pending->toolCallId)
                ?? throw ApprovalNotFound::withId($pending->toolCallId);
        }

        $this->events->append($run, EventType::ApprovalRequested, [
            'approval_id' => $approval->id,
            'tool_call_id' => $pending->toolCallId,
            'tool' => $pending->toolName,
            'arguments' => $pending->arguments,
            'reason' => $pending->reason,
            'expires_at' => $approval->expires_at?->toISOString(),
        ]);

        return $approval;
    }
}
