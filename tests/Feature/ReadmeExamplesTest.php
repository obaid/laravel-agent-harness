<?php

declare(strict_types=1);

/**
 * The README is the normative v1 product contract. Every example in it runs
 * here, verbatim, so the documentation cannot drift away from the package.
 */

use Clutch\Laravel\Artifacts\Artifact;
use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Events\ClutchEventRecorded;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\PublishingAgent;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\Tests\Fixtures\Agents\ScoringAgent;
use Clutch\Laravel\Tests\Fixtures\Workflows\OnboardCustomer;
use Clutch\Laravel\ValueObjects\RunBudget;
use Clutch\Laravel\Workflows\Workflow;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->owner = $this->user();
});

it('runs the quick start', function (): void {
    Clutch::fake([
        'Research our three closest competitors' => ClutchResult::text('Lead with onboarding speed.'),
    ]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->name('Competitor research')
        ->create();

    $result = $session->prompt(
        'Research our three closest competitors and recommend a positioning wedge.'
    );

    $payload = [
        'session_id' => $session->id,
        'run_id' => $result->run->id,
        'text' => $result->text,
        'artifacts' => $result->artifacts,
        'usage' => $result->usage,
    ];

    expect($payload['session_id'])->toStartWith('ses_')
        ->and($payload['run_id'])->toStartWith('run_')
        ->and($payload['text'])->toBe('Lead with onboarding speed.')
        ->and($payload['artifacts'])->toBeEmpty()
        ->and($payload['usage']->totalTokens())->toBeGreaterThan(0);
});

it('continues a session', function (): void {
    Clutch::fake([
        ClutchResult::text('The wedge is onboarding.'),
        ClutchResult::text('Here is the one-page memo.'),
    ]);

    $created = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $created->prompt('Research the competitors.');

    $session = Clutch::session($created->id)->authorizeFor($this->owner);

    $result = $session->prompt('Turn the recommendation into a one-page strategy memo.');

    expect($result->text)->toBe('Here is the one-page memo.');
});

it('queues a background run', function (): void {
    $fake = Clutch::fake([ClutchResult::text('Report ready.')]);

    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();

    $run = $session->queue('Analyze every page on our website and produce a content gap report.');

    expect(['session_id' => $session->id, 'run_id' => $run->id, 'status' => $run->status])
        ->toHaveKeys(['session_id', 'run_id', 'status']);

    $fake->assertRunQueued('Analyze every page');
});

it('configures queue behavior per session', function (): void {
    Clutch::fake();

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->onConnection('redis')
        ->onQueue('agents')
        ->timeout(seconds: 900)
        ->create();

    expect($session->queue_connection)->toBe('redis')
        ->and($session->queue)->toBe('agents')
        ->and($session->timeout_seconds)->toBe(900);
});

it('streams from a controller', function (): void {
    Clutch::fake([ClutchResult::text('Making progress now.')]);

    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();

    // Exactly the README's controller body, returned from a real route.
    Route::get('/_test/stream/{session}', fn (string $sessionId) => Clutch::session($sessionId)
        ->stream('Create the report and explain your progress.')
        ->usingVercelDataProtocol());

    $response = $this->get("/_test/stream/{$session->id}");

    $response->assertOk();

    expect($response->streamedContent())
        ->toContain('"type":"text-delta"')
        ->toContain('"type":"finish"')
        ->toContain('data: [DONE]');
});

it('streams the harness event envelope when no protocol is requested', function (): void {
    Clutch::fake([ClutchResult::text('Progress.')]);

    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();

    Route::get('/_test/raw-stream/{session}', fn (string $sessionId) => Clutch::session($sessionId)
        ->stream('Create the report.'));

    $response = $this->get("/_test/raw-stream/{$session->id}");

    expect($response->streamedContent())
        ->toContain('"type":"run.started"')
        ->toContain('"type":"text.delta"')
        ->toContain('"type":"run.completed"');
});

it('approves and rejects through the run', function (): void {
    $fake = Clutch::fake([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['article_id' => 123]),
        ClutchResult::text('Published.'),
    ]);

    $session = Clutch::agent(PublishingAgent::class)->for($this->owner)->create();
    $paused = $session->prompt('Publish the approved article.');

    $approvalId = $paused->pendingApprovals->first()->id;

    $run = Clutch::run($paused->run->id)->authorizeFor($this->owner);

    $run->approve(
        approvalId: $approvalId,
        reason: 'The content has been reviewed and may be published.',
    );

    $fake->assertApproved('publish_article');
});

it('rejects an action', function (): void {
    $fake = Clutch::fake([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['article_id' => 123]),
        ClutchResult::text('Understood.'),
    ]);

    $session = Clutch::agent(PublishingAgent::class)->for($this->owner)->create();
    $paused = $session->prompt('Publish the draft.');

    $run = Clutch::run($paused->run->id)->authorizeFor($this->owner);

    $run->reject(
        approvalId: $paused->pendingApprovals->first()->id,
        reason: 'Do not publish this draft. Remove the unsupported claim first.',
    );

    $fake->assertRejected('publish_article');
});

it('configures a session-level permission policy', function (): void {
    Clutch::fake();

    $session = Clutch::agent(PublishingAgent::class)
        ->for($this->owner)
        ->permissions(PermissionMode::ApproveSensitive)
        ->create();

    expect($session->permission_mode)->toBe(PermissionMode::ApproveSensitive);
});

it('constrains a session with a budget', function (): void {
    Clutch::fake();

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->budget(new RunBudget(
            maxSteps: 40,
            maxToolCalls: 100,
            maxTokens: 250_000,
            maxCostUsd: 8.00,
            maxDurationSeconds: 900,
        ))
        ->create();

    expect($session->budget()->maxSteps)->toBe(40)
        ->and($session->budget()->maxCostUsd)->toBe(8.0);
});

it('cancels a run', function (): void {
    $fake = Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $created = Clutch::coordinator()->createRun($session, 'A long job.');

    $run = Clutch::run($created->id)->authorizeFor($this->owner);

    $run->cancel(reason: 'The request is no longer needed.');

    $fake->assertRunCancelled();
});

it('attaches an artifact from a tool', function (): void {
    Storage::fake('s3');

    Clutch::fake([
        ClutchResult::text('Report attached.')->withArtifact(
            Artifact::fromStorage(disk: 's3', path: 'reports/content-gap-2026-08-24.pdf')
                ->name('Content gap report')
                ->mimeType('application/pdf')
                ->metadata(['pages' => 18]),
        ),
    ]);

    Storage::disk('s3')->put('reports/content-gap-2026-08-24.pdf', '%PDF-1.4');

    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $result = $session->prompt('Produce the report.');

    expect($result->artifacts)->toHaveCount(1)
        ->and($result->artifacts->first()->name)->toBe('Content gap report')
        ->and($result->artifacts->first()->metadata)->toBe(['pages' => 18]);

    // Retrieve them from a run as well.
    expect(Clutch::run($result->run->id)->artifacts)->toHaveCount(1);
});

it('lets applications listen without depending on the transport', function (): void {
    Event::fake([ClutchEventRecorded::class]);

    Clutch::fake([ClutchResult::text('Done.')]);

    Clutch::agent(ResearchAgent::class)->for($this->owner)->create()->prompt('Do it.');

    Event::assertDispatched(ClutchEventRecorded::class, function (ClutchEventRecorded $event): bool {
        return $event->type === 'run.completed'
            && $event->envelope['run_id'] === $event->runId
            && isset($event->envelope['sequence'], $event->envelope['occurred_at'], $event->envelope['payload']);
    });
});

it('returns structured output', function (): void {
    Clutch::fake([
        ClutchResult::structured(['score' => 87, 'notes' => 'Strong, but thin on evidence.']),
    ]);

    $session = Clutch::agent(ScoringAgent::class)->for($this->owner)->create();

    $result = $session->prompt('Score this draft against our content rubric.');

    $score = $result->structured['score'];

    expect($score)->toBe(87)
        ->and($result->run->structured_output)->toBe(['score' => 87, 'notes' => 'Strong, but thin on evidence.']);
});

it('selects a driver explicitly', function (): void {
    Clutch::fake();

    $session = Clutch::agent(ResearchAgent::class)
        ->driver('laravel-ai')
        ->for($this->owner)
        ->create();

    expect($session->driver)->toBe('laravel-ai');
});

it('builds a runtime session with a workspace and sandbox', function (): void {
    Clutch::fake();

    $session = Clutch::runtime('fake')
        ->for($this->owner)
        ->workspace('acme/website')
        ->sandbox('e2b')
        ->create();

    expect($session->runtime_name)->toBe('fake')
        ->and($session->workspace_id)->toBe('acme/website')
        ->and($session->configuration['sandbox'])->toBe('e2b');
});

it('fakes the entire harness with the documented assertions', function (): void {
    Clutch::fake([
        'Draft a brief' => ClutchResult::text('The proposed brief...'),
    ]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->create();

    $session->queue('Draft a brief');

    Clutch::assertSessionCreated(ResearchAgent::class);
    Clutch::assertRunQueued('Draft a brief');
    Clutch::assertRunCompleted();
    Clutch::assertNothingAwaitingApproval();
});

it('tests a paused run', function (): void {
    Clutch::fake([
        ClutchResult::awaitingApproval(
            tool: 'publish_article',
            arguments: ['article_id' => 123],
        ),
    ]);

    $session = Clutch::agent(PublishingAgent::class)->for($this->owner)->create();

    $run = $session->queue('Publish the approved article.');

    Clutch::assertApprovalRequested('publish_article');

    expect($run->refresh()->status->value)->toBe('awaiting_approval');
});

it('runs the workflows example', function (): void {
    OnboardCustomer::$provisioned = [];

    Clutch::fake([ClutchResult::text('They sell bottom-up, through the product.')]);

    $run = OnboardCustomer::dispatch(['domain' => 'acme.com']);

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingApproval)
        ->and(OnboardCustomer::$provisioned)->toBe([]);

    Workflow::resume($run->id, ['approved' => true]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->structured_output['provisioned'])->toBe('acme.com')
        // Re-entering the body did not research the same domain twice.
        ->and(OnboardCustomer::$provisioned)->toBe(['acme.com']);
});

it('runs the workflows rejection example', function (): void {
    OnboardCustomer::$provisioned = [];

    Clutch::fake([ClutchResult::text('They sell bottom-up.')]);

    $run = OnboardCustomer::dispatch(['domain' => 'acme.com']);

    Workflow::resume($run->id, ['approved' => false, 'reason' => 'Fix the pricing claim.']);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->structured_output['skipped'])->toBe('Fix the pricing claim.')
        ->and(OnboardCustomer::$provisioned)->toBe([]);
});
