<?php

declare(strict_types=1);

/**
 * The README is the normative v1 product contract. Every example in it runs
 * here, verbatim, so the documentation cannot drift away from the package.
 */

use AgentHarness\Laravel\Artifacts\Artifact;
use AgentHarness\Laravel\Enums\PermissionMode;
use AgentHarness\Laravel\Events\HarnessEventRecorded;
use AgentHarness\Laravel\Facades\Harness;
use AgentHarness\Laravel\Runtime\HarnessResult;
use AgentHarness\Laravel\Tests\Fixtures\Agents\PublishingAgent;
use AgentHarness\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use AgentHarness\Laravel\Tests\Fixtures\Agents\ScoringAgent;
use AgentHarness\Laravel\ValueObjects\RunBudget;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->owner = $this->user();
});

it('runs the quick start', function (): void {
    Harness::fake([
        'Research our three closest competitors' => HarnessResult::text('Lead with onboarding speed.'),
    ]);

    $session = Harness::agent(ResearchAgent::class)
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
    Harness::fake([
        HarnessResult::text('The wedge is onboarding.'),
        HarnessResult::text('Here is the one-page memo.'),
    ]);

    $created = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $created->prompt('Research the competitors.');

    $session = Harness::session($created->id)->authorizeFor($this->owner);

    $result = $session->prompt('Turn the recommendation into a one-page strategy memo.');

    expect($result->text)->toBe('Here is the one-page memo.');
});

it('queues a background run', function (): void {
    $fake = Harness::fake([HarnessResult::text('Report ready.')]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $run = $session->queue('Analyze every page on our website and produce a content gap report.');

    expect(['session_id' => $session->id, 'run_id' => $run->id, 'status' => $run->status])
        ->toHaveKeys(['session_id', 'run_id', 'status']);

    $fake->assertRunQueued('Analyze every page');
});

it('configures queue behavior per session', function (): void {
    Harness::fake();

    $session = Harness::agent(ResearchAgent::class)
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
    Harness::fake([HarnessResult::text('Making progress now.')]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    // Exactly the README's controller body, returned from a real route.
    Route::get('/_test/stream/{session}', fn (string $sessionId) => Harness::session($sessionId)
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
    Harness::fake([HarnessResult::text('Progress.')]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    Route::get('/_test/raw-stream/{session}', fn (string $sessionId) => Harness::session($sessionId)
        ->stream('Create the report.'));

    $response = $this->get("/_test/raw-stream/{$session->id}");

    expect($response->streamedContent())
        ->toContain('"type":"run.started"')
        ->toContain('"type":"text.delta"')
        ->toContain('"type":"run.completed"');
});

it('approves and rejects through the run', function (): void {
    $fake = Harness::fake([
        HarnessResult::awaitingApproval(tool: 'publish_article', arguments: ['article_id' => 123]),
        HarnessResult::text('Published.'),
    ]);

    $session = Harness::agent(PublishingAgent::class)->for($this->owner)->create();
    $paused = $session->prompt('Publish the approved article.');

    $approvalId = $paused->pendingApprovals->first()->id;

    $run = Harness::run($paused->run->id)->authorizeFor($this->owner);

    $run->approve(
        approvalId: $approvalId,
        reason: 'The content has been reviewed and may be published.',
    );

    $fake->assertApproved('publish_article');
});

it('rejects an action', function (): void {
    $fake = Harness::fake([
        HarnessResult::awaitingApproval(tool: 'publish_article', arguments: ['article_id' => 123]),
        HarnessResult::text('Understood.'),
    ]);

    $session = Harness::agent(PublishingAgent::class)->for($this->owner)->create();
    $paused = $session->prompt('Publish the draft.');

    $run = Harness::run($paused->run->id)->authorizeFor($this->owner);

    $run->reject(
        approvalId: $paused->pendingApprovals->first()->id,
        reason: 'Do not publish this draft. Remove the unsupported claim first.',
    );

    $fake->assertRejected('publish_article');
});

it('configures a session-level permission policy', function (): void {
    Harness::fake();

    $session = Harness::agent(PublishingAgent::class)
        ->for($this->owner)
        ->permissions(PermissionMode::ApproveSensitive)
        ->create();

    expect($session->permission_mode)->toBe(PermissionMode::ApproveSensitive);
});

it('constrains a session with a budget', function (): void {
    Harness::fake();

    $session = Harness::agent(ResearchAgent::class)
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
    $fake = Harness::fake([HarnessResult::text('ok')]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $created = Harness::coordinator()->createRun($session, 'A long job.');

    $run = Harness::run($created->id)->authorizeFor($this->owner);

    $run->cancel(reason: 'The request is no longer needed.');

    $fake->assertRunCancelled();
});

it('attaches an artifact from a tool', function (): void {
    Storage::fake('s3');

    Harness::fake([
        HarnessResult::text('Report attached.')->withArtifact(
            Artifact::fromStorage(disk: 's3', path: 'reports/content-gap-2026-08-24.pdf')
                ->name('Content gap report')
                ->mimeType('application/pdf')
                ->metadata(['pages' => 18]),
        ),
    ]);

    Storage::disk('s3')->put('reports/content-gap-2026-08-24.pdf', '%PDF-1.4');

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $result = $session->prompt('Produce the report.');

    expect($result->artifacts)->toHaveCount(1)
        ->and($result->artifacts->first()->name)->toBe('Content gap report')
        ->and($result->artifacts->first()->metadata)->toBe(['pages' => 18]);

    // Retrieve them from a run as well.
    expect(Harness::run($result->run->id)->artifacts)->toHaveCount(1);
});

it('lets applications listen without depending on the transport', function (): void {
    Event::fake([HarnessEventRecorded::class]);

    Harness::fake([HarnessResult::text('Done.')]);

    Harness::agent(ResearchAgent::class)->for($this->owner)->create()->prompt('Do it.');

    Event::assertDispatched(HarnessEventRecorded::class, function (HarnessEventRecorded $event): bool {
        return $event->type === 'run.completed'
            && $event->envelope['run_id'] === $event->runId
            && isset($event->envelope['sequence'], $event->envelope['occurred_at'], $event->envelope['payload']);
    });
});

it('returns structured output', function (): void {
    Harness::fake([
        HarnessResult::structured(['score' => 87, 'notes' => 'Strong, but thin on evidence.']),
    ]);

    $session = Harness::agent(ScoringAgent::class)->for($this->owner)->create();

    $result = $session->prompt('Score this draft against our content rubric.');

    $score = $result->structured['score'];

    expect($score)->toBe(87)
        ->and($result->run->structured_output)->toBe(['score' => 87, 'notes' => 'Strong, but thin on evidence.']);
});

it('selects a driver explicitly', function (): void {
    Harness::fake();

    $session = Harness::agent(ResearchAgent::class)
        ->driver('laravel-ai')
        ->for($this->owner)
        ->create();

    expect($session->driver)->toBe('laravel-ai');
});

it('builds a runtime session with a workspace and sandbox', function (): void {
    Harness::fake();

    $session = Harness::runtime('fake')
        ->for($this->owner)
        ->workspace('acme/website')
        ->sandbox('e2b')
        ->create();

    expect($session->runtime_name)->toBe('fake')
        ->and($session->workspace_id)->toBe('acme/website')
        ->and($session->configuration['sandbox'])->toBe('e2b');
});

it('fakes the entire harness with the documented assertions', function (): void {
    Harness::fake([
        'Draft a brief' => HarnessResult::text('The proposed brief...'),
    ]);

    $session = Harness::agent(ResearchAgent::class)
        ->for($this->owner)
        ->create();

    $session->queue('Draft a brief');

    Harness::assertSessionCreated(ResearchAgent::class);
    Harness::assertRunQueued('Draft a brief');
    Harness::assertRunCompleted();
    Harness::assertNothingAwaitingApproval();
});

it('tests a paused run', function (): void {
    Harness::fake([
        HarnessResult::awaitingApproval(
            tool: 'publish_article',
            arguments: ['article_id' => 123],
        ),
    ]);

    $session = Harness::agent(PublishingAgent::class)->for($this->owner)->create();

    $run = $session->queue('Publish the approved article.');

    Harness::assertApprovalRequested('publish_article');

    expect($run->refresh()->status->value)->toBe('awaiting_approval');
});
