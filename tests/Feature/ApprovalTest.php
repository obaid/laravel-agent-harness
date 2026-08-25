<?php

declare(strict_types=1);

use Clutch\Laravel\Approvals\ApprovalBroker;
use Clutch\Laravel\Enums\ApprovalStatus;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Enums\SessionStatus;
use Clutch\Laravel\Events\ApprovalRequested;
use Clutch\Laravel\Exceptions\ApprovalAlreadyResolved;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\PublishingAgent;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->owner = $this->user();

    $this->clutch = Clutch::fake([
        ClutchResult::awaitingApproval(
            tool: 'publish_article',
            arguments: ['article_id' => 123],
            reason: 'Publishing is irreversible.',
        ),
        ClutchResult::text('The article is published.'),
    ]);

    $this->session = Clutch::agent(PublishingAgent::class)->for($this->owner)->create();
});

it('pauses the run and records a durable approval', function (): void {
    $result = $this->session->prompt('Publish the approved article.');

    expect($result->isAwaitingApproval())->toBeTrue()
        ->and($result->run->status)->toBe(RunStatus::AwaitingApproval);

    $approval = Approval::query()->firstOrFail();

    expect($approval->tool_name)->toBe('publish_article')
        ->and($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->arguments)->toBe(['article_id' => 123])
        ->and($approval->reason)->toBe('Publishing is irreversible.');

    // The session stays occupied while it waits, so nothing else starts.
    expect($this->session->refresh()->status)->toBe(SessionStatus::AwaitingApproval)
        ->and($this->session->active_run_id)->toBe($result->run->id);

    $this->clutch->assertApprovalRequested('publish_article');
});

it('checkpoints before pausing so another process can resume', function (): void {
    $result = $this->session->prompt('Publish the approved article.');

    $checkpoint = $result->run->checkpoints()->latest('created_at')->first();

    expect($checkpoint)->not->toBeNull()
        ->and($checkpoint->reason)->toBe('approval_pause')
        ->and($checkpoint->hasIntactPayload())->toBeTrue();
});

it('notifies the application when a decision is needed', function (): void {
    Event::fake([ApprovalRequested::class]);

    $this->session->prompt('Publish the approved article.');

    Event::assertDispatched(ApprovalRequested::class, function (ApprovalRequested $event): bool {
        return $event->approval->tool_name === 'publish_article';
    });
});

it('resumes the run when the approval is granted in another request', function (): void {
    $paused = $this->session->prompt('Publish the approved article.');

    $approval = Approval::query()->firstOrFail();

    // A second request, with no memory of the first, resolves the decision.
    $run = Clutch::run($paused->run->id)->authorizeFor($this->owner);

    $run->approve($approval->id, reason: 'Reviewed and cleared for publication.', actor: $this->owner);

    app(\Clutch\Laravel\Runtime\RunCoordinator::class)->resumeAfterApproval($run->refresh());

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->output_text)->toBe('The article is published.');

    $this->clutch->assertApproved('publish_article');
    $this->clutch->assertNothingAwaitingApproval();
});

it('resolves a decision exactly once', function (): void {
    $paused = $this->session->prompt('Publish the approved article.');
    $approval = Approval::query()->firstOrFail();
    $run = $paused->run;

    $first = $run->approve($approval->id, 'Cleared.', $this->owner);
    $second = $run->approve($approval->id, 'Cleared again.', $this->owner);

    // Repeating the identical decision is a no-op that returns what exists.
    expect($first->id)->toBe($second->id)
        ->and($second->status)->toBe(ApprovalStatus::Approved)
        ->and($second->decision_reason)->toBe('Cleared.')
        ->and($second->version)->toBe(2);

    // Exactly one resolution event was recorded.
    expect($run->events()->where('type', 'approval.resolved')->count())->toBe(1);
});

it('refuses to reverse a resolved decision', function (): void {
    $paused = $this->session->prompt('Publish the approved article.');
    $approval = Approval::query()->firstOrFail();

    $paused->run->approve($approval->id, 'Cleared.', $this->owner);

    expect(fn () => $paused->run->reject($approval->id, 'Actually, no.', $this->owner))
        ->toThrow(ApprovalAlreadyResolved::class);
});

it('records who decided and why', function (): void {
    $paused = $this->session->prompt('Publish the approved article.');
    $approval = Approval::query()->firstOrFail();

    $paused->run->reject($approval->id, 'Remove the unsupported claim first.', $this->owner);

    $approval->refresh();

    expect($approval->status)->toBe(ApprovalStatus::Rejected)
        ->and($approval->decision_reason)->toBe('Remove the unsupported claim first.')
        ->and($approval->resolved_by_id)->toBe((string) $this->owner->id)
        ->and($approval->resolved_by_type)->toBe($this->owner->getMorphClass())
        ->and($approval->resolved_at)->not->toBeNull();

    $this->clutch->assertRejected('publish_article');
});

it('carries a rejection back to the agent so it can react', function (): void {
    $paused = $this->session->prompt('Publish the approved article.');
    $approval = Approval::query()->firstOrFail();

    $paused->run->reject($approval->id, 'Do not publish this draft.', $this->owner);

    app(\Clutch\Laravel\Runtime\RunCoordinator::class)->resumeAfterApproval($paused->run->refresh());

    $run = $paused->run->refresh();

    expect($run->status)->toBe(RunStatus::Completed);

    $denied = $run->events()->where('type', 'tool.call.failed')->first();

    expect($denied)->not->toBeNull()
        ->and($denied->payload['denied'])->toBeTrue()
        ->and($denied->payload['error'])->toBe('Do not publish this draft.');
});

it('does not resume until every pending decision is in', function (): void {
    $this->clutch->script([
        new \Clutch\Laravel\Testing\ScriptedResponse(
            \Clutch\Laravel\Testing\ScriptedResponse::APPROVAL,
            pendingApprovals: [
                new \Clutch\Laravel\Data\PendingApproval('call_a', 'publish_article', ['id' => 1]),
                new \Clutch\Laravel\Data\PendingApproval('call_b', 'send_email', ['to' => 'a@b.com']),
            ],
        ),
        ClutchResult::text('Both done.'),
    ]);

    $paused = $this->session->prompt('Publish and announce the article.');

    $broker = app(ApprovalBroker::class);

    expect($broker->allResolved($paused->run))->toBeFalse();

    $first = Approval::query()->where('tool_call_id', 'call_a')->firstOrFail();
    $paused->run->approve($first->id, actor: $this->owner);

    expect($broker->allResolved($paused->run))->toBeFalse()
        ->and($paused->run->refresh()->status)->toBe(RunStatus::AwaitingApproval);

    $second = Approval::query()->where('tool_call_id', 'call_b')->firstOrFail();
    $paused->run->approve($second->id, actor: $this->owner);

    expect($broker->allResolved($paused->run->refresh()))->toBeTrue();
});

it('keeps one approval row per tool call even if the pause is recorded twice', function (): void {
    $paused = $this->session->prompt('Publish the approved article.');

    $broker = app(ApprovalBroker::class);

    // Simulate a duplicated worker delivery re-recording the same pause.
    $broker->request($paused->run, [
        new \Clutch\Laravel\Data\PendingApproval('call_dup', 'publish_article', ['article_id' => 123]),
    ]);
    $broker->request($paused->run, [
        new \Clutch\Laravel\Data\PendingApproval('call_dup', 'publish_article', ['article_id' => 123]),
    ]);

    expect(Approval::query()->where('tool_call_id', 'call_dup')->count())->toBe(1);
});
