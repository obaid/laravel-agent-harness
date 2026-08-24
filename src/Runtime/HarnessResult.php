<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Runtime;

use AgentHarness\Laravel\Data\PendingApproval;
use AgentHarness\Laravel\Enums\RunStatus;
use AgentHarness\Laravel\Models\Approval;
use AgentHarness\Laravel\Models\Artifact;
use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Testing\ScriptedResponse;
use AgentHarness\Laravel\ValueObjects\BudgetUsage;
use AgentHarness\Laravel\ValueObjects\NormalizedFailure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Stringable;

/**
 * The terminal outcome of a synchronous run.
 *
 * @implements Arrayable<string, mixed>
 */
class HarnessResult implements Arrayable, Stringable
{
    /**
     * @param  Collection<int, Artifact>  $artifacts
     * @param  Collection<int, Approval>  $pendingApprovals
     * @param  array<string, mixed>|null  $structured
     */
    public function __construct(
        public readonly Run $run,
        public readonly ?string $text = null,
        public readonly ?array $structured = null,
        public readonly Collection $artifacts = new Collection,
        public readonly Collection $pendingApprovals = new Collection,
        public readonly BudgetUsage $usage = new BudgetUsage,
        public readonly ?NormalizedFailure $failure = null,
    ) {}

    /**
     * Build a result from a run's committed terminal state.
     */
    public static function fromRun(Run $run): self
    {
        return new self(
            run: $run,
            text: $run->output_text,
            structured: $run->structured_output,
            artifacts: $run->artifacts()->get(),
            pendingApprovals: $run->pendingApprovals(),
            usage: $run->usage(),
            failure: $run->failure_category !== null
                ? new NormalizedFailure(
                    $run->failure_category,
                    (string) $run->failure_message,
                    $run->failure_exception_class,
                )
                : null,
        );
    }

    // Test scripting helpers ---------------------------------------------
    //
    // These build scripted outcomes for `Harness::fake()`. They live here so a
    // test reads the way the production call does.

    /**
     * Script a plain text response.
     */
    public static function text(string $text): ScriptedResponse
    {
        return new ScriptedResponse(ScriptedResponse::TEXT, text: $text);
    }

    /**
     * Script a structured response.
     *
     * @param  array<string, mixed>  $structured
     */
    public static function structured(array $structured, ?string $text = null): ScriptedResponse
    {
        return new ScriptedResponse(
            ScriptedResponse::STRUCTURED,
            text: $text ?? (string) json_encode($structured),
            structured: $structured,
        );
    }

    /**
     * Script a run that pauses awaiting approval of one tool call.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function awaitingApproval(
        string $tool,
        array $arguments = [],
        ?string $reason = null,
        ?string $toolCallId = null,
    ): ScriptedResponse {
        return new ScriptedResponse(
            ScriptedResponse::APPROVAL,
            pendingApprovals: [new PendingApproval(
                toolCallId: $toolCallId ?? 'call_'.substr(md5($tool.serialize($arguments)), 0, 16),
                toolName: $tool,
                arguments: $arguments,
                reason: $reason,
            )],
        );
    }

    /**
     * Script a failing run.
     */
    public static function failure(string $message = 'The run failed.'): ScriptedResponse
    {
        return new ScriptedResponse(ScriptedResponse::FAILURE, failureMessage: $message);
    }

    public function status(): RunStatus
    {
        return $this->run->status;
    }

    public function isCompleted(): bool
    {
        return $this->run->status === RunStatus::Completed;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->run->status === RunStatus::AwaitingApproval;
    }

    public function isFailed(): bool
    {
        return $this->run->status === RunStatus::Failed;
    }

    public function isCancelled(): bool
    {
        return $this->run->status === RunStatus::Cancelled;
    }

    public function exceededBudget(): bool
    {
        return $this->run->status === RunStatus::BudgetExceeded;
    }

    /**
     * Read a value from the structured output.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->structured, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->run->id,
            'session_id' => $this->run->session_id,
            'status' => $this->run->status->value,
            'text' => $this->text,
            'structured' => $this->structured,
            'artifacts' => $this->artifacts
                ->map(fn (Artifact $artifact): array => [
                    'id' => $artifact->id,
                    'name' => $artifact->name,
                    'kind' => $artifact->kind->value,
                    'mime_type' => $artifact->mime_type,
                    'size_bytes' => $artifact->size_bytes,
                ])
                ->all(),
            'pending_approvals' => $this->pendingApprovals
                ->map(fn (Approval $approval): array => [
                    'id' => $approval->id,
                    'tool' => $approval->tool_name,
                    'reason' => $approval->reason,
                ])
                ->all(),
            'usage' => $this->usage->toArray(),
            'failure' => $this->failure?->toArray(),
        ];
    }

    public function __toString(): string
    {
        return (string) $this->text;
    }
}
