<?php

declare(strict_types=1);

use AgentHarness\Laravel\Enums\RunStatus;
use AgentHarness\Laravel\Facades\Harness;
use AgentHarness\Laravel\Models\Checkpoint;
use AgentHarness\Laravel\Models\RunEvent;
use AgentHarness\Laravel\Runtime\HarnessResult;
use AgentHarness\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->owner = $this->user();
    $this->harness = Harness::fake([HarnessResult::text('The report is ready.')]);
    $this->session = Harness::agent(ResearchAgent::class)->for($this->owner)->name('Research')->create();
});

it('lists sessions', function (): void {
    // Table output goes through Symfony's renderer rather than the console
    // helpers expectsOutputToContain() observes, so this reads the real buffer.
    expect(Artisan::call('harness:sessions'))->toBe(0);

    expect(Artisan::output())
        ->toContain($this->session->id)
        ->toContain('Research')
        ->toContain('ResearchAgent')
        ->toContain('ready');
});

it('reports when there are no sessions to list', function (): void {
    $this->session->forceDelete();

    $this->artisan('harness:sessions')
        ->expectsOutputToContain('No harness sessions found.')
        ->assertSuccessful();
});

it('inspects a run', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->artisan("harness:run {$run->id}")
        ->expectsOutputToContain($run->id)
        ->expectsOutputToContain('completed')
        ->expectsOutputToContain('The report is ready.')
        ->assertSuccessful();
});

it('fails cleanly on an unknown run', function (): void {
    $this->artisan('harness:run run_does_not_exist')
        ->expectsOutputToContain('No run found')
        ->assertFailed();
});

it('replays events for a run', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->artisan("harness:events {$run->id}")
        ->expectsOutputToContain('run.created')
        ->expectsOutputToContain('run.completed')
        ->assertSuccessful();
});

it('redacts sensitive values in event output because they never reached storage', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    app(AgentHarness\Laravel\Runtime\EventStore::class)->append(
        $run,
        AgentHarness\Laravel\Enums\EventType::DriverEvent,
        ['api_key' => 'sk-live-should-never-persist', 'note' => 'visible'],
    );

    $stored = RunEvent::query()->where('type', 'driver.event')->latest('sequence')->firstOrFail();

    expect($stored->payload['api_key'])->toBe('[REDACTED]')
        ->and($stored->payload['note'])->toBe('visible');

    $this->artisan("harness:events {$run->id} --payloads")
        ->doesntExpectOutputToContain('sk-live-should-never-persist')
        ->assertSuccessful();
});

it('cancels a run from the console', function (): void {
    $run = Harness::coordinator()->createRun($this->session, 'A long job.');

    $this->artisan("harness:cancel {$run->id} --reason=\"No longer needed\"")
        ->assertSuccessful();

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled);
});

it('says so when cancelling an already-finished run', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->artisan("harness:cancel {$run->id}")
        ->expectsOutputToContain('already finished')
        ->assertSuccessful();
});

it('queues a retry from the console', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->harness->script([HarnessResult::text('Second attempt.')]);

    $this->artisan("harness:retry {$run->id} --reset-budget")
        ->expectsOutputToContain('Queued attempt 2')
        ->assertSuccessful();
});

it('refuses to retry a run that is still going', function (): void {
    $run = Harness::coordinator()->createRun($this->session, 'A long job.');

    $this->artisan("harness:retry {$run->id}")
        ->expectsOutputToContain('Cancel it before retrying')
        ->assertFailed();
});

it('prunes aged records without touching the resume point', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    // Age everything well past the retention window.
    RunEvent::query()->update(['occurred_at' => now()->subDays(400)]);
    Checkpoint::query()->update(['created_at' => now()->subDays(400)]);

    $checkpointsBefore = Checkpoint::query()->count();

    $this->artisan('harness:prune')->assertSuccessful();

    expect(RunEvent::query()->where('run_id', $run->id)->count())->toBe(0);

    // The newest checkpoint per session is the resume point and survives.
    expect(Checkpoint::query()->count())->toBe(1)
        ->and($checkpointsBefore)->toBeGreaterThan(1);
});

it('keeps data an unfinished run still needs', function (): void {
    $run = Harness::coordinator()->createRun($this->session, 'Still going.');

    RunEvent::query()->update(['occurred_at' => now()->subDays(400)]);

    $this->artisan('harness:prune')->assertSuccessful();

    expect(RunEvent::query()->where('run_id', $run->id)->count())->toBeGreaterThan(0);
});

it('reports when nothing is old enough to prune', function (): void {
    $this->session->prompt('Write the report.');

    $this->artisan('harness:prune')
        ->expectsOutputToContain('Nothing was old enough to prune.')
        ->assertSuccessful();
});

it('sweeps for abandoned runs', function (): void {
    $this->artisan('harness:reap')
        ->expectsOutputToContain('Abandoned run sweep complete.')
        ->assertSuccessful();
});

it('generates an agent that can carry a durable session', function (): void {
    $this->artisan('make:harness-agent', ['name' => 'SupportAgent'])->assertSuccessful();

    $path = app_path('Ai/Agents/SupportAgent.php');

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('use Laravel\Ai\Concerns\RemembersConversations;')
        ->toContain('class SupportAgent implements Agent')
        ->toContain('use Promptable;');

    @unlink($path);
});
