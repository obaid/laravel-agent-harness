<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\ApprovalStatus;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->owner = $this->user();
    $this->stranger = $this->user('stranger@example.com');

    $this->clutch = Clutch::fake([ClutchResult::text('The report is ready.')]);
    $this->session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
});

it('returns a run to its owner', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->actingAs($this->owner)
        ->getJson("/api/clutch/runs/{$run->id}")
        ->assertOk()
        ->assertJsonPath('run_id', $run->id)
        ->assertJsonPath('status', RunStatus::Completed->value)
        ->assertJsonPath('text', 'The report is ready.');
});

it('hides a run from a different participant', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->actingAs($this->stranger)
        ->getJson("/api/clutch/runs/{$run->id}")
        ->assertForbidden();
});

it('replays run events from a cursor', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $response = $this->actingAs($this->owner)
        ->getJson("/api/clutch/runs/{$run->id}/events/history?after=2")
        ->assertOk();

    $sequences = collect($response->json('data'))->pluck('sequence');

    expect($sequences->first())->toBe(3)
        ->and($sequences->all())->toBe($sequences->sort()->values()->all());

    // The envelope is stable regardless of transport.
    $response->assertJsonStructure([
        'data' => [['id', 'session_id', 'run_id', 'sequence', 'type', 'occurred_at', 'payload']],
        'cursor',
        'has_more',
    ]);
});

it('lists a participant\'s own sessions only', function (): void {
    Clutch::agent(ResearchAgent::class)->for($this->stranger)->create();

    $this->actingAs($this->owner)
        ->getJson('/api/clutch/sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->session->id);
});

it('cancels a run through the API', function (): void {
    $run = Clutch::coordinator()->createRun($this->session, 'A long job.');

    $this->actingAs($this->owner)
        ->postJson("/api/clutch/runs/{$run->id}/cancel", ['reason' => 'Not needed.'])
        ->assertOk()
        ->assertJsonPath('status', RunStatus::Cancelled->value);

    expect($run->refresh()->cancellation_reason)->toBe('Not needed.');
});

it('refuses to cancel another participant\'s run', function (): void {
    $run = Clutch::coordinator()->createRun($this->session, 'A long job.');

    $this->actingAs($this->stranger)
        ->postJson("/api/clutch/runs/{$run->id}/cancel")
        ->assertForbidden();

    expect($run->refresh()->status)->toBe(RunStatus::Created);
});

it('records an approval decision and resumes the run', function (): void {
    $this->clutch->script([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
        ClutchResult::text('Published.'),
    ]);

    $run = $this->session->prompt('Publish it.')->run;
    $approval = Approval::query()->firstOrFail();

    $this->actingAs($this->owner)
        ->postJson("/api/clutch/runs/{$run->id}/approvals/{$approval->id}/approve", [
            'reason' => 'Reviewed and cleared.',
        ])
        ->assertOk()
        ->assertJsonPath('status', ApprovalStatus::Approved->value);

    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});

it('is idempotent when the same decision arrives twice', function (): void {
    $this->clutch->script([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
        ClutchResult::text('Published.'),
    ]);

    $run = $this->session->prompt('Publish it.')->run;
    $approval = Approval::query()->firstOrFail();

    $url = "/api/clutch/runs/{$run->id}/approvals/{$approval->id}/approve";

    $this->actingAs($this->owner)->postJson($url)->assertOk();
    $this->actingAs($this->owner)->postJson($url)->assertOk();

    expect($run->refresh()->events()->where('type', 'approval.resolved')->count())->toBe(1);
});

it('refuses to reverse a resolved decision through the API', function (): void {
    $this->clutch->script([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
        ClutchResult::text('Published.'),
    ]);

    $run = $this->session->prompt('Publish it.')->run;
    $approval = Approval::query()->firstOrFail();

    $this->actingAs($this->owner)
        ->postJson("/api/clutch/runs/{$run->id}/approvals/{$approval->id}/approve")
        ->assertOk();

    $this->actingAs($this->owner)
        ->postJson("/api/clutch/runs/{$run->id}/approvals/{$approval->id}/reject")
        ->assertStatus(409);
});

it('will not let another participant decide an approval', function (): void {
    $this->clutch->script([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
    ]);

    $run = $this->session->prompt('Publish it.')->run;
    $approval = Approval::query()->firstOrFail();

    $this->actingAs($this->stranger)
        ->postJson("/api/clutch/runs/{$run->id}/approvals/{$approval->id}/approve")
        ->assertForbidden();

    expect($approval->refresh()->status)->toBe(ApprovalStatus::Pending);
});

it('serves an artifact to its owner and no one else', function (): void {
    Storage::fake('artifacts');
    config()->set('clutch.artifacts.disk', 'artifacts');

    $this->clutch->script([
        ClutchResult::text('Done.')->withArtifact(
            \Clutch\Laravel\Artifacts\Artifact::fromContents('report bytes', 'r.txt')->name('Report'),
        ),
    ]);

    $artifact = $this->session->prompt('Write it.')->artifacts->first();

    // Depending on the disk the owner either streams the bytes or is redirected
    // to a temporary URL; what matters is that they are not refused.
    $owner = $this->actingAs($this->owner)->get("/api/clutch/artifacts/{$artifact->id}");

    expect($owner->getStatusCode())->toBeIn([200, 302])
        ->and($artifact->contents())->toBe('report bytes');

    $this->actingAs($this->stranger)
        ->get("/api/clutch/artifacts/{$artifact->id}")
        ->assertForbidden();
});

it('queues a retry through the API', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->clutch->script([ClutchResult::text('Second attempt.')]);

    $this->actingAs($this->owner)
        ->postJson("/api/clutch/runs/{$run->id}/retry")
        ->assertStatus(202)
        ->assertJsonPath('attempt', 2);
});

it('streams run events as server-sent events', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $response = $this->actingAs($this->owner)
        ->get("/api/clutch/runs/{$run->id}/events?after=0");

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    $body = $response->streamedContent();

    // Frames carry id: and data: only. Naming the SSE event after the type
    // would stop EventSource.onmessage firing, silently breaking the most
    // obvious client anyone writes, so the type travels in the payload.
    expect($body)->toContain('"type":"run.created"')
        ->toContain('"type":"run.completed"')
        ->toContain('data: [DONE]')
        ->not->toContain('event: run.');

    // The sequence still rides as the SSE id, so a browser can resume with
    // Last-Event-ID rather than only with our own cursor.
    expect($body)->toContain('id: 1');
});
