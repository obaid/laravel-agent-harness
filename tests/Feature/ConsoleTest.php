<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\Checkpoint;
use Clutch\Laravel\Models\RunEvent;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->owner = $this->user();
    $this->clutch = Clutch::fake([ClutchResult::text('The report is ready.')]);
    $this->session = Clutch::agent(ResearchAgent::class)->for($this->owner)->name('Research')->create();
});

it('lists sessions', function (): void {
    // Table output goes through Symfony's renderer rather than the console
    // helpers expectsOutputToContain() observes, so this reads the real buffer.
    expect(Artisan::call('clutch:sessions'))->toBe(0);

    expect(Artisan::output())
        ->toContain($this->session->id)
        ->toContain('Research')
        ->toContain('ResearchAgent')
        ->toContain('ready');
});

it('reports when there are no sessions to list', function (): void {
    $this->session->forceDelete();

    $this->artisan('clutch:sessions')
        ->expectsOutputToContain('No Clutch sessions found.')
        ->assertSuccessful();
});

it('inspects a run', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->artisan("clutch:run {$run->id}")
        ->expectsOutputToContain($run->id)
        ->expectsOutputToContain('completed')
        ->expectsOutputToContain('The report is ready.')
        ->assertSuccessful();
});

it('fails cleanly on an unknown run', function (): void {
    $this->artisan('clutch:run run_does_not_exist')
        ->expectsOutputToContain('No run found')
        ->assertFailed();
});

it('replays events for a run', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->artisan("clutch:events {$run->id}")
        ->expectsOutputToContain('run.created')
        ->expectsOutputToContain('run.completed')
        ->assertSuccessful();
});

it('redacts sensitive values in event output because they never reached storage', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    app(\Clutch\Laravel\Runtime\EventStore::class)->append(
        $run,
        \Clutch\Laravel\Enums\EventType::DriverEvent,
        ['api_key' => 'sk-live-should-never-persist', 'note' => 'visible'],
    );

    $stored = RunEvent::query()->where('type', 'driver.event')->latest('sequence')->firstOrFail();

    expect($stored->payload['api_key'])->toBe('[REDACTED]')
        ->and($stored->payload['note'])->toBe('visible');

    $this->artisan("clutch:events {$run->id} --payloads")
        ->doesntExpectOutputToContain('sk-live-should-never-persist')
        ->assertSuccessful();
});

it('cancels a run from the console', function (): void {
    $run = Clutch::coordinator()->createRun($this->session, 'A long job.');

    $this->artisan("clutch:cancel {$run->id} --reason=\"No longer needed\"")
        ->assertSuccessful();

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled);
});

it('says so when cancelling an already-finished run', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->artisan("clutch:cancel {$run->id}")
        ->expectsOutputToContain('already finished')
        ->assertSuccessful();
});

it('queues a retry from the console', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    $this->clutch->script([ClutchResult::text('Second attempt.')]);

    $this->artisan("clutch:retry {$run->id} --reset-budget")
        ->expectsOutputToContain('Queued attempt 2')
        ->assertSuccessful();
});

it('refuses to retry a run that is still going', function (): void {
    $run = Clutch::coordinator()->createRun($this->session, 'A long job.');

    $this->artisan("clutch:retry {$run->id}")
        ->expectsOutputToContain('Cancel it before retrying')
        ->assertFailed();
});

it('prunes aged records without touching the resume point', function (): void {
    $run = $this->session->prompt('Write the report.')->run;

    // Age everything well past the retention window.
    RunEvent::query()->update(['occurred_at' => now()->subDays(400)]);
    Checkpoint::query()->update(['created_at' => now()->subDays(400)]);

    $checkpointsBefore = Checkpoint::query()->count();

    $this->artisan('clutch:prune')->assertSuccessful();

    expect(RunEvent::query()->where('run_id', $run->id)->count())->toBe(0);

    // The newest checkpoint per session is the resume point and survives.
    expect(Checkpoint::query()->count())->toBe(1)
        ->and($checkpointsBefore)->toBeGreaterThan(1);
});

it('keeps data an unfinished run still needs', function (): void {
    $run = Clutch::coordinator()->createRun($this->session, 'Still going.');

    RunEvent::query()->update(['occurred_at' => now()->subDays(400)]);

    $this->artisan('clutch:prune')->assertSuccessful();

    expect(RunEvent::query()->where('run_id', $run->id)->count())->toBeGreaterThan(0);
});

it('reports when nothing is old enough to prune', function (): void {
    $this->session->prompt('Write the report.');

    $this->artisan('clutch:prune')
        ->expectsOutputToContain('Nothing was old enough to prune.')
        ->assertSuccessful();
});

it('sweeps for abandoned runs', function (): void {
    $this->artisan('clutch:reap')
        ->expectsOutputToContain('Abandoned run sweep complete.')
        ->assertSuccessful();
});

it('generates an agent that can carry a durable session', function (): void {
    $this->artisan('make:clutch-agent', ['name' => 'SupportAgent'])->assertSuccessful();

    $path = app_path('Ai/Agents/SupportAgent.php');

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('use Laravel\Ai\Concerns\RemembersConversations;')
        ->toContain('class SupportAgent implements Agent')
        ->toContain('use Promptable;');

    @unlink($path);
});
