<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tools;

use Closure;
use Clutch\Laravel\Artifacts\ArtifactRegistrar;
use Clutch\Laravel\Contracts\IdempotentTool;
use Clutch\Laravel\Data\ToolInvocation;
use Clutch\Laravel\Guards\LoopGuard;
use Clutch\Laravel\Guards\ToolDeadline;
use Clutch\Laravel\Models\ToolExecution;
use Clutch\Laravel\Support\Id;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Keeps a side effect from happening twice.
 *
 * The key and a pending record are committed *before* the tool runs, so a
 * worker that dies mid-execution leaves evidence the action was attempted.
 * A retry then returns the stored result instead of repeating the side effect.
 */
class ToolExecutionLedger
{
    public function __construct(
        protected ?SpillPolicy $spill = null,
        protected ?LoopGuard $guard = null,
        protected ?ToolDeadline $deadline = null,
    ) {}

    /**
     * Execute a tool through the ledger.
     *
     * Tools that do not declare an idempotency contract run unguarded — the
     * harness records them for audit but makes no duplicate-suppression claim,
     * because it cannot safely make one on their behalf.
     *
     * @param  Closure(): mixed  $execute
     */
    public function guard(ToolInvocation $invocation, ?object $tool, Closure $execute): mixed
    {
        // A blocked call never reaches the tool, so the refusal is what the
        // model gets back as the result. That is deliberate: an agent stuck in
        // a loop needs to be told, not silently starved.
        $verdict = $this->guard?->inspect($invocation);

        if ($verdict !== null && $verdict->isBlocked()) {
            return (string) $verdict->message;
        }

        $execute = $this->withDeadline($invocation, $execute);

        $key = $tool instanceof IdempotentTool
            ? $tool->idempotencyKey($invocation)
            : null;

        if ($key === null) {
            return $this->recordUnguarded($invocation, $execute);
        }

        $existing = $this->findByKey($invocation->sessionId, $key);

        if ($existing?->isCompleted()) {
            return $existing->result;
        }

        $record = $existing ?? $this->reserve($invocation, $key);

        // Another worker won the reservation race and is mid-flight, or already
        // finished between our read and our write.
        if ($record === null) {
            $winner = $this->findByKey($invocation->sessionId, $key);

            if ($winner?->isCompleted()) {
                return $winner->result;
            }

            $record = $winner;
        }

        $startedAt = microtime(true);

        try {
            $result = $execute();
        } catch (Throwable $e) {
            $record?->update([
                'status' => ToolExecution::FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => Carbon::now(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }

        $record?->update([
            'status' => ToolExecution::COMPLETED,
            'result' => is_string($result) ? $result : json_encode($result),
            'completed_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }

    /**
     * Wrap a tool call in its deadline, when one applies.
     *
     * @param  Closure(): mixed  $execute
     * @return Closure(): mixed
     */
    protected function withDeadline(ToolInvocation $invocation, Closure $execute): Closure
    {
        $deadline = $this->deadline;

        if (! $deadline instanceof ToolDeadline) {
            return $execute;
        }

        return static fn (): mixed => $deadline->guard($invocation, $execute);
    }

    /**
     * Replace an oversized result with a preview and an artifact reference.
     *
     * Called by the driver once it knows which run the result belongs to, since
     * the ledger itself has no artifact registrar of its own.
     */
    public function spillIfOversized(
        ToolInvocation $invocation,
        string $result,
        ArtifactRegistrar $artifacts,
    ): string {
        if (! $this->spill instanceof SpillPolicy || ! $this->spill->shouldSpill($result)) {
            return $result;
        }

        return (string) $this->spill->spill(
            $artifacts,
            $invocation->toolName,
            $invocation->toolCallId,
            $result,
        );
    }

    /**
     * Look up a previously recorded side effect.
     */
    public function findByKey(string $sessionId, string $key): ?ToolExecution
    {
        return ToolExecution::query()
            ->where('session_id', $sessionId)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * Determine whether a keyed side effect has already completed.
     */
    public function hasCompleted(string $sessionId, string $key): bool
    {
        return $this->findByKey($sessionId, $key)?->isCompleted() ?? false;
    }

    /**
     * Claim the key with a pending record, or return null if another worker won.
     */
    protected function reserve(ToolInvocation $invocation, string $key): ?ToolExecution
    {
        try {
            return ToolExecution::query()->create([
                'id' => Id::toolCall(),
                'session_id' => $invocation->sessionId,
                'run_id' => $invocation->runId,
                'tool_call_id' => $invocation->toolCallId,
                'tool_name' => $invocation->toolName,
                'idempotency_key' => $key,
                'arguments_digest' => $invocation->argumentsDigest(),
                'status' => ToolExecution::PENDING,
                'started_at' => Carbon::now(),
            ]);
        } catch (QueryException) {
            // The unique constraint on (session_id, idempotency_key) is the
            // authority here, not our earlier read.
            return null;
        }
    }

    /**
     * Record a non-idempotent execution for audit only.
     *
     * @param  Closure(): mixed  $execute
     */
    protected function recordUnguarded(ToolInvocation $invocation, Closure $execute): mixed
    {
        $record = ToolExecution::query()->create([
            'id' => Id::toolCall(),
            'session_id' => $invocation->sessionId,
            'run_id' => $invocation->runId,
            'tool_call_id' => $invocation->toolCallId,
            'tool_name' => $invocation->toolName,
            'idempotency_key' => null,
            'arguments_digest' => $invocation->argumentsDigest(),
            'status' => ToolExecution::PENDING,
            'started_at' => Carbon::now(),
        ]);

        $startedAt = microtime(true);

        try {
            $result = $execute();
        } catch (Throwable $e) {
            $record->update([
                'status' => ToolExecution::FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => Carbon::now(),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }

        $record->update([
            'status' => ToolExecution::COMPLETED,
            'result' => is_string($result) ? $result : json_encode($result),
            'completed_at' => Carbon::now(),
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $result;
    }
}
