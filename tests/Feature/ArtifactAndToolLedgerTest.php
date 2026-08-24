<?php

declare(strict_types=1);

use AgentHarness\Laravel\Artifacts\Artifact;
use AgentHarness\Laravel\Data\ToolInvocation;
use AgentHarness\Laravel\Enums\ArtifactKind;
use AgentHarness\Laravel\Facades\Harness;
use AgentHarness\Laravel\Models\Artifact as ArtifactModel;
use AgentHarness\Laravel\Models\ToolExecution;
use AgentHarness\Laravel\Runtime\HarnessResult;
use AgentHarness\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use AgentHarness\Laravel\Tests\Fixtures\Tools\PublishArticle;
use AgentHarness\Laravel\Tools\ToolExecutionLedger;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('artifacts');
    config()->set('agent-harness.artifacts.disk', 'artifacts');

    $this->owner = $this->user();
    PublishArticle::$published = [];
});

it('attaches an artifact to the run that produced it', function (): void {
    Harness::fake([
        HarnessResult::text('Report ready.')->withArtifact(
            Artifact::fromContents('# Content gap report', 'reports/content-gap.md')
                ->name('Content gap report')
                ->mimeType('text/markdown')
                ->metadata(['pages' => 18]),
        ),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $result = $session->prompt('Produce a content gap report.');

    expect($result->artifacts)->toHaveCount(1);

    $artifact = $result->artifacts->first();

    expect($artifact->name)->toBe('Content gap report')
        ->and($artifact->kind)->toBe(ArtifactKind::Document)
        ->and($artifact->mime_type)->toBe('text/markdown')
        ->and($artifact->metadata)->toBe(['pages' => 18])
        ->and($artifact->session_id)->toBe($session->id)
        ->and($artifact->run_id)->toBe($result->run->id);

    // The bytes live on the disk, never in the database.
    Storage::disk('artifacts')->assertExists('reports/content-gap.md');
    expect($artifact->contents())->toBe('# Content gap report');
});

it('records integrity metadata for an artifact', function (): void {
    Harness::fake([
        HarnessResult::text('Done.')->withArtifact(
            Artifact::fromContents('hello world', 'notes.txt')->name('Notes'),
        ),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $artifact = $session->prompt('Write notes.')->artifacts->first();

    expect($artifact->sha256)->toBe(hash('sha256', 'hello world'))
        ->and($artifact->size_bytes)->toBe(11)
        ->and($artifact->hasIntactContents())->toBeTrue();
});

it('emits an artifact event without copying the bytes into it', function (): void {
    Harness::fake([
        HarnessResult::text('Done.')->withArtifact(
            Artifact::fromContents('secret bytes that must not be duplicated', 'out.txt')->name('Out'),
        ),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->prompt('Write it.')->run;

    $event = $run->events()->where('type', 'artifact.created')->firstOrFail();

    expect($event->payload)->toHaveKey('artifact_id')
        ->and($event->payload)->toHaveKey('sha256')
        ->and(json_encode($event->payload))->not->toContain('secret bytes');
});

it('references an artifact that already exists on a disk', function (): void {
    Storage::disk('artifacts')->put('reports/existing.pdf', '%PDF-1.4 fake');

    Harness::fake([
        HarnessResult::text('Attached.')->withArtifact(
            Artifact::fromStorage('artifacts', 'reports/existing.pdf')
                ->name('Existing report')
                ->mimeType('application/pdf'),
        ),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $artifact = $session->prompt('Attach it.')->artifacts->first();

    expect($artifact->kind)->toBe(ArtifactKind::Document)
        ->and($artifact->exists())->toBeTrue()
        ->and(ArtifactModel::query()->count())->toBe(1);
});

it('runs an idempotent tool once, however many times it is delivered', function (): void {
    $ledger = app(ToolExecutionLedger::class);
    $tool = new PublishArticle;

    Harness::fake([HarnessResult::text('ok')]);
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = Harness::coordinator()->createRun($session, 'Publish it.');

    $invocation = new ToolInvocation(
        sessionId: $session->id,
        runId: $run->id,
        toolCallId: 'call_1',
        toolName: 'publish_article',
        arguments: ['article_id' => 42],
    );

    $first = $ledger->guard($invocation, $tool, fn (): string => $tool->handle(
        new Laravel\Ai\Tools\Request(['article_id' => 42], 'call_1')
    ));

    // A duplicated delivery: a different tool-call ID, the same side effect.
    $retry = new ToolInvocation(
        sessionId: $session->id,
        runId: $run->id,
        toolCallId: 'call_2',
        toolName: 'publish_article',
        arguments: ['article_id' => 42],
    );

    $second = $ledger->guard($retry, $tool, fn (): string => $tool->handle(
        new Laravel\Ai\Tools\Request(['article_id' => 42], 'call_2')
    ));

    expect(PublishArticle::$published)->toBe([42])
        ->and($second)->toBe($first)
        ->and(ToolExecution::query()->count())->toBe(1);
});

it('lets a different side effect through', function (): void {
    $ledger = app(ToolExecutionLedger::class);
    $tool = new PublishArticle;

    Harness::fake([HarnessResult::text('ok')]);
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = Harness::coordinator()->createRun($session, 'Publish both.');

    foreach ([42, 43] as $index => $id) {
        $ledger->guard(
            new ToolInvocation($session->id, $run->id, "call_{$index}", 'publish_article', ['article_id' => $id]),
            $tool,
            fn (): string => $tool->handle(new Laravel\Ai\Tools\Request(['article_id' => $id], "call_{$index}")),
        );
    }

    expect(PublishArticle::$published)->toBe([42, 43])
        ->and(ToolExecution::query()->count())->toBe(2);
});

it('records a failed tool without claiming it succeeded', function (): void {
    $ledger = app(ToolExecutionLedger::class);
    $tool = new PublishArticle;

    Harness::fake([HarnessResult::text('ok')]);
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = Harness::coordinator()->createRun($session, 'Publish it.');

    $invocation = new ToolInvocation($session->id, $run->id, 'call_1', 'publish_article', ['article_id' => 1]);

    expect(fn () => $ledger->guard($invocation, $tool, function (): string {
        throw new RuntimeException('The publishing API is down.');
    }))->toThrow(RuntimeException::class);

    $record = ToolExecution::query()->firstOrFail();

    expect($record->status)->toBe(ToolExecution::FAILED)
        ->and($record->error_message)->toBe('The publishing API is down.')
        ->and($ledger->hasCompleted($session->id, $tool->idempotencyKey($invocation)))->toBeFalse();
});

it('records a non-idempotent tool for audit without suppressing duplicates', function (): void {
    $ledger = app(ToolExecutionLedger::class);

    Harness::fake([HarnessResult::text('ok')]);
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = Harness::coordinator()->createRun($session, 'Search.');

    $calls = 0;

    foreach (['call_1', 'call_2'] as $id) {
        $ledger->guard(
            new ToolInvocation($session->id, $run->id, $id, 'search_web', ['q' => 'laravel']),
            tool: null,
            execute: function () use (&$calls): string {
                $calls++;

                return 'results';
            },
        );
    }

    // Without an idempotency contract the harness makes no duplicate-suppression
    // claim; it records both for audit.
    expect($calls)->toBe(2)
        ->and(ToolExecution::query()->count())->toBe(2)
        ->and(ToolExecution::query()->whereNull('idempotency_key')->count())->toBe(2);
});
