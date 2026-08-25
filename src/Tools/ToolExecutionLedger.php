<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tools;

use Closure;
use Clutch\Laravel\Contracts\IdempotentTool;
use Clutch\Laravel\Data\ToolInvocation;
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
    /**
     * Execute a tool through the ledger.
     *
     * Tools that do not declare an idempotency contract run unguarded — the
     * harness records them for audit but makes no duplicate-suppression claim,
     * because it cannot safely make one on their behalf.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $execute
     * @return TResult
     */
    public function guard(ToolInvocation $invocation, ?object $tool, Closure $execute): mixed
    {
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
     * @template TResult
     *
     * @param  Closure(): TResult  $execute
     * @return TResult
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
