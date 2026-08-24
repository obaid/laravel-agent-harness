<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Testing;

use AgentHarness\Laravel\Artifacts\Artifact;
use AgentHarness\Laravel\Data\PendingApproval;
use AgentHarness\Laravel\ValueObjects\BudgetUsage;

/**
 * One scripted outcome for the fake driver.
 *
 * Built through the static helpers on `HarnessResult`, which read naturally at
 * the call site: `HarnessResult::text('...')`.
 */
final class ScriptedResponse
{
    public const TEXT = 'text';

    public const STRUCTURED = 'structured';

    public const APPROVAL = 'approval';

    public const FAILURE = 'failure';

    /** @var array<int, array{tool: string, arguments: array<string, mixed>, result: mixed}> */
    public array $toolCalls = [];

    /** @var array<int, Artifact> */
    public array $artifacts = [];

    public BudgetUsage $usage;

    /**
     * @param  array<string, mixed>|null  $structured
     * @param  array<int, PendingApproval>  $pendingApprovals
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $text = null,
        public readonly ?array $structured = null,
        public readonly array $pendingApprovals = [],
        public readonly ?string $failureMessage = null,
        ?BudgetUsage $usage = null,
    ) {
        $this->usage = $usage ?? new BudgetUsage(steps: 1, promptTokens: 12, completionTokens: 8);
    }

    /**
     * Script a tool call that runs before the response is produced.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function withToolCall(string $tool, array $arguments = [], mixed $result = 'ok'): self
    {
        $this->toolCalls[] = ['tool' => $tool, 'arguments' => $arguments, 'result' => $result];

        return $this;
    }

    /**
     * Script an artifact the run should attach.
     */
    public function withArtifact(Artifact $artifact): self
    {
        $this->artifacts[] = $artifact;

        return $this;
    }

    /**
     * Override the usage the fake run reports.
     */
    public function withUsage(BudgetUsage $usage): self
    {
        $this->usage = $usage;

        return $this;
    }
}
