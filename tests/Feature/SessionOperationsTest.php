<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\ApprovalStatus;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Enums\SessionStatus;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Jobs\ExpireApprovals;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;

beforeEach(function (): void {
    $this->owner = $this->user();
    $this->clutch = Clutch::fake([ClutchResult::text('Done.')]);
    $this->session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
});

it('stops a session and keeps its history', function (): void {
    $run = $this->session->prompt('Do the work.')->run;

    $stopped = $this->session->refresh()->stop();

    expect($stopped->status)->toBe(SessionStatus::Stopped)
        ->and($stopped->runs()->count())->toBe(1)
        ->and($run->refresh()->status)->toBe(RunStatus::Completed);

    // The final checkpoint records why the session stopped.
    expect($stopped->checkpoints()->latest('created_at')->first()->reason)->toBe('session_stopped');
});

it('accepts new work again after being stopped', function (): void {
    $this->session->prompt('First.');
    $this->session->refresh()->stop();

    $this->clutch->script([ClutchResult::text('Second.')]);

    $result = $this->session->refresh()->prompt('Second.');

    expect($result->isCompleted())->toBeTrue()
        ->and($result->text)->toBe('Second.');
});

it('destroys a session', function (): void {
    $id = $this->session->id;

    $this->session->destroySession();

    expect(Session::query()->find($id))->toBeNull()
        ->and(Session::withTrashed()->find($id)?->status)->toBe(SessionStatus::Destroyed);
});

it('expires an approval whose window elapsed and lets the run react', function (): void {
    config()->set('clutch.approvals.expires_after', 3600);

    // Rebuild the broker so it picks up the configured window.
    $this->app->forgetInstance(\Clutch\Laravel\Approvals\ApprovalBroker::class);
    $this->app->forgetInstance(\Clutch\Laravel\Runtime\RunCoordinator::class);

    $this->clutch->script([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
        ClutchResult::text('Understood, not published.'),
    ]);

    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $paused = $session->prompt('Publish it.');

    $approval = Approval::query()->firstOrFail();

    expect($approval->expires_at)->not->toBeNull();

    // The window elapses without anyone deciding.
    $approval->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->app->call([new ExpireApprovals, 'handle']);

    expect($approval->refresh()->status)->toBe(ApprovalStatus::Expired);

    // An expired approval reads as a rejection, so the run resumes rather than
    // hanging forever.
    expect($paused->run->refresh()->status)->toBe(RunStatus::Completed);
});

it('leaves an approval with no window open indefinitely', function (): void {
    $this->clutch->script([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
    ]);

    $paused = $this->session->prompt('Publish it.');

    expect(Approval::query()->firstOrFail()->expires_at)->toBeNull();

    $this->app->call([new ExpireApprovals, 'handle']);

    expect(Approval::query()->firstOrFail()->status)->toBe(ApprovalStatus::Pending)
        ->and($paused->run->refresh()->status)->toBe(RunStatus::AwaitingApproval);
});

it('approves a tool call with edited arguments', function (): void {
    $this->clutch->script([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['article_id' => 123]),
        ClutchResult::text('Published the corrected article.'),
    ]);

    $paused = $this->session->prompt('Publish it.');
    $approval = Approval::query()->firstOrFail();

    app(\Clutch\Laravel\Approvals\ApprovalBroker::class)->approveWithArguments(
        $paused->run,
        $approval->id,
        ['article_id' => 456],
        'Wrong article; corrected the id.',
        $this->owner,
    );

    $approval->refresh();

    expect($approval->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->arguments)->toBe(['article_id' => 123])
        ->and($approval->edited_arguments)->toBe(['article_id' => 456])
        // The edit is what the tool should actually run with.
        ->and($approval->effectiveArguments())->toBe(['article_id' => 456]);
});

it('keeps approval arguments encrypted at rest', function (): void {
    $this->clutch->script([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['secret_ref' => 'ABC-123']),
    ]);

    $this->session->prompt('Publish it.');

    $raw = Illuminate\Support\Facades\DB::table('clutch_approvals')->first();

    expect($raw->arguments)->not->toContain('ABC-123')
        ->and(Approval::query()->firstOrFail()->arguments)->toBe(['secret_ref' => 'ABC-123']);
});

it('keeps run input encrypted at rest', function (): void {
    $run = $this->session->prompt('A confidential internal question.')->run;

    $raw = Illuminate\Support\Facades\DB::table('clutch_runs')->where('id', $run->id)->first();

    expect($raw->input)->not->toContain('confidential')
        ->and($run->promptText())->toBe('A confidential internal question.');
});

it('keeps checkpoint payloads encrypted at rest', function (): void {
    $this->session->prompt('Do the work.');

    $raw = Illuminate\Support\Facades\DB::table('clutch_checkpoints')->first();

    expect($raw->payload)->not->toContain('fake-conversation')
        ->and($this->session->checkpoints()->first()->payload)->toHaveKey('conversation_id');
});
