<?php

declare(strict_types=1);

use AgentHarness\Laravel\Enums\ApprovalStatus;
use AgentHarness\Laravel\Enums\RunStatus;
use AgentHarness\Laravel\Facades\Harness;
use AgentHarness\Laravel\Models\Approval;
use AgentHarness\Laravel\Runtime\HarnessResult;
use AgentHarness\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->owner = $this->user();
    $this->stranger = $this->user('stranger@example.com');

    $this->harness = Harness::fake([HarnessResult::text('The report is ready.')]);
    $this->session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
});

it('returns a run to its owner', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->actingAs($this->owner)
        ->getJson("/api/agent-harness/runs/{$run->id}")
        ->assertOk()
        ->assertJsonPath('run_id', $run->id)
        ->assertJsonPath('status', RunStatus::Completed->value)
        ->assertJsonPath('text', 'The report is ready.');
});

it('hides a run from a different participant', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->actingAs($this->stranger)
        ->getJson("/api/agent-harness/runs/{$run->id}")
        ->assertForbidden();
});

it('replays run events from a cursor', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $response = $this->actingAs($this->owner)
        ->getJson("/api/agent-harness/runs/{$run->id}/events/history?after=2")
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
    Harness::agent(ResearchAgent::class)->for($this->stranger)->create();

    $this->actingAs($this->owner)
        ->getJson('/api/agent-harness/sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->session->id);
});

it('cancels a run through the API', function (): void {
    $run = Harness::coordinator()->createRun($this->session, 'A long job.');

    $this->actingAs($this->owner)
        ->postJson("/api/agent-harness/runs/{$run->id}/cancel", ['reason' => 'Not needed.'])
        ->assertOk()
        ->assertJsonPath('status', RunStatus::Cancelled->value);

    expect($run->refresh()->cancellation_reason)->toBe('Not needed.');
});

it('refuses to cancel another participant\'s run', function (): void {
    $run = Harness::coordinator()->createRun($this->session, 'A long job.');

    $this->actingAs($this->stranger)
        ->postJson("/api/agent-harness/runs/{$run->id}/cancel")
        ->assertForbidden();

    expect($run->refresh()->status)->toBe(RunStatus::Created);
});

it('records an approval decision and resumes the run', function (): void {
    $this->harness->script([
        HarnessResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
        HarnessResult::text('Published.'),
    ]);

    $run = $this->session->prompt('Publish it.')->run;
    $approval = Approval::query()->firstOrFail();

    $this->actingAs($this->owner)
        ->postJson("/api/agent-harness/runs/{$run->id}/approvals/{$approval->id}/approve", [
            'reason' => 'Reviewed and cleared.',
        ])
        ->assertOk()
        ->assertJsonPath('status', ApprovalStatus::Approved->value);

    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});

it('is idempotent when the same decision arrives twice', function (): void {
    $this->harness->script([
        HarnessResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
        HarnessResult::text('Published.'),
    ]);

    $run = $this->session->prompt('Publish it.')->run;
    $approval = Approval::query()->firstOrFail();

    $url = "/api/agent-harness/runs/{$run->id}/approvals/{$approval->id}/approve";

    $this->actingAs($this->owner)->postJson($url)->assertOk();
    $this->actingAs($this->owner)->postJson($url)->assertOk();

    expect($run->refresh()->events()->where('type', 'approval.resolved')->count())->toBe(1);
});

it('refuses to reverse a resolved decision through the API', function (): void {
    $this->harness->script([
        HarnessResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
        HarnessResult::text('Published.'),
    ]);

    $run = $this->session->prompt('Publish it.')->run;
    $approval = Approval::query()->firstOrFail();

    $this->actingAs($this->owner)
        ->postJson("/api/agent-harness/runs/{$run->id}/approvals/{$approval->id}/approve")
        ->assertOk();

    $this->actingAs($this->owner)
        ->postJson("/api/agent-harness/runs/{$run->id}/approvals/{$approval->id}/reject")
        ->assertStatus(409);
});

it('will not let another participant decide an approval', function (): void {
    $this->harness->script([
        HarnessResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
    ]);

    $run = $this->session->prompt('Publish it.')->run;
    $approval = Approval::query()->firstOrFail();

    $this->actingAs($this->stranger)
        ->postJson("/api/agent-harness/runs/{$run->id}/approvals/{$approval->id}/approve")
        ->assertForbidden();

    expect($approval->refresh()->status)->toBe(ApprovalStatus::Pending);
});

it('serves an artifact to its owner and no one else', function (): void {
    Storage::fake('artifacts');
    config()->set('agent-harness.artifacts.disk', 'artifacts');

    $this->harness->script([
        HarnessResult::text('Done.')->withArtifact(
            AgentHarness\Laravel\Artifacts\Artifact::fromContents('report bytes', 'r.txt')->name('Report'),
        ),
    ]);

    $artifact = $this->session->prompt('Write it.')->artifacts->first();

    // Depending on the disk the owner either streams the bytes or is redirected
    // to a temporary URL; what matters is that they are not refused.
    $owner = $this->actingAs($this->owner)->get("/api/agent-harness/artifacts/{$artifact->id}");

    expect($owner->getStatusCode())->toBeIn([200, 302])
        ->and($artifact->contents())->toBe('report bytes');

    $this->actingAs($this->stranger)
        ->get("/api/agent-harness/artifacts/{$artifact->id}")
        ->assertForbidden();
});

it('queues a retry through the API', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->harness->script([HarnessResult::text('Second attempt.')]);

    $this->actingAs($this->owner)
        ->postJson("/api/agent-harness/runs/{$run->id}/retry")
        ->assertStatus(202)
        ->assertJsonPath('attempt', 2);
});

it('streams run events as server-sent events', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $response = $this->actingAs($this->owner)
        ->get("/api/agent-harness/runs/{$run->id}/events?after=0");

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    $body = $response->streamedContent();

    expect($body)->toContain('event: run.created')
        ->toContain('event: run.completed')
        ->toContain('data: [DONE]');
});
