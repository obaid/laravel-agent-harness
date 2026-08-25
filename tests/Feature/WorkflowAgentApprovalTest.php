<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Workflows\NestedApprovalWorkflow;
use Clutch\Laravel\Workflows\Workflow;

beforeEach(function (): void {
    NestedApprovalWorkflow::reset();
    $this->owner = $this->user();
});

it('parks the workflow when an agent it prompted stops for approval', function (): void {
    Clutch::fake([
        ClutchResult::awaitingApproval(
            tool: 'publish_article',
            arguments: ['article_id' => 42],
            reason: 'Publishing is irreversible.',
        ),
    ]);

    $result = NestedApprovalWorkflow::start()->for($this->owner)->runNow([]);

    expect($result->run->status)->toBe(RunStatus::AwaitingApproval)
        // The body must not have continued past a prompt that never finished.
        ->and(NestedApprovalWorkflow::$reachedEnd)->toBe(0);

    // What a human sees is the agent's real tool call, not an invented one.
    $approval = Approval::query()->where('run_id', $result->run->id)->firstOrFail();

    expect($approval->arguments['tool'])->toBe('publish_article')
        ->and($approval->arguments['arguments'])->toBe(['article_id' => 42]);
});

it('delivers the decision to the agent and finishes the workflow', function (): void {
    Clutch::fake([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['article_id' => 42]),
        ClutchResult::text('Published.'),
    ]);

    $paused = NestedApprovalWorkflow::start()->for($this->owner)->runNow([]);

    $result = Workflow::resumeNow($paused->run->id, ['approved' => true]);

    expect($result->run->status)->toBe(RunStatus::Completed)
        ->and($result->run->structured_output['said'])->toBe('Published.')
        ->and(NestedApprovalWorkflow::$reachedEnd)->toBe(1);
});

it('does not record a step whose agent never finished', function (): void {
    Clutch::fake([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['article_id' => 42]),
        ClutchResult::text('Published.'),
    ]);

    $paused = NestedApprovalWorkflow::start()->for($this->owner)->runNow([]);

    expect(NestedApprovalWorkflow::$stepBodyRan)->toBe(1);

    Workflow::resumeNow($paused->run->id, ['approved' => true]);

    // The step body runs a second time precisely because the first attempt
    // never produced a result. Recording it would have stranded the agent.
    expect(NestedApprovalWorkflow::$stepBodyRan)->toBe(2);
});

it('carries a rejection through to the agent rather than stranding it', function (): void {
    Clutch::fake([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['article_id' => 42]),
        ClutchResult::text('Understood, leaving it as a draft.'),
    ]);

    $paused = NestedApprovalWorkflow::start()->for($this->owner)->runNow([]);

    $result = Workflow::resumeNow($paused->run->id, ['approved' => false, 'reason' => 'not yet']);

    expect($result->run->status)->toBe(RunStatus::Completed)
        ->and($result->run->structured_output['said'])->toBe('Understood, leaving it as a draft.');

    // The agent's own approval is resolved, not left pending forever.
    expect(Approval::query()->where('status', 'pending')->count())->toBe(0);
});
