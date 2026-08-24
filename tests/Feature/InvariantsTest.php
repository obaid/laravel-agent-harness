<?php

declare(strict_types=1);

/**
 * The architectural invariants from docs/ARCHITECTURE.md §19.
 *
 * These are the promises the package makes. Each one gets an explicit test so a
 * regression shows up as a named failure rather than as odd behavior in
 * production.
 */

use AgentHarness\Laravel\Data\DriverCheckpoint;
use AgentHarness\Laravel\Enums\RunStatus;
use AgentHarness\Laravel\Exceptions\HarnessCapabilityUnsupported;
use AgentHarness\Laravel\Exceptions\SessionBusy;
use AgentHarness\Laravel\Facades\Harness;
use AgentHarness\Laravel\Jobs\ExecuteAgentRun;
use AgentHarness\Laravel\Models\Approval;
use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Models\RunEvent;
use AgentHarness\Laravel\Runtime\DriverRegistry;
use AgentHarness\Laravel\Runtime\HarnessResult;
use AgentHarness\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use AgentHarness\Laravel\ValueObjects\DriverCapabilities;

beforeEach(function (): void {
    $this->owner = $this->user();
    $this->harness = Harness::fake([HarnessResult::text('Done.')]);
});

it('1. allows a session at most one active run', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    Harness::coordinator()->createRun($session, 'First.');

    expect(fn () => Harness::coordinator()->createRun($session->refresh(), 'Second.'))
        ->toThrow(SessionBusy::class);

    expect(Run::query()->active()->count())->toBe(1);
});

it('2. gives a run exactly one immutable terminal state', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->prompt('Do it.')->run;

    expect($run->status)->toBe(RunStatus::Completed);

    // A terminal status permits no further transitions at all.
    expect($run->status->allowedTransitions())->toBe([]);

    $terminals = $run->events()->get()->filter(fn (RunEvent $e): bool => $e->type->isTerminal());

    expect($terminals)->toHaveCount(1);
});

it('3. never repeats or decreases an event sequence within a run', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->prompt('Do it.')->run;

    $sequences = $run->events()->pluck('sequence');

    expect($sequences->duplicates())->toBeEmpty()
        ->and($sequences->all())->toBe($sequences->sort()->values()->all())
        ->and($sequences->first())->toBe(1);
});

it('4. records a matching event for every lifecycle transition', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->queue('Do it.');

    $types = $run->refresh()->events()->pluck('type')->map->value;

    expect($types)->toContain('run.created')
        ->toContain('run.queued')
        ->toContain('run.started')
        ->toContain('run.completed');
});

it('5. never exposes a terminal event without terminal state and result', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->prompt('Do it.')->run;

    $terminal = $run->events()->where('type', 'run.completed')->firstOrFail();

    // Reading the run at the moment the terminal event is visible must show a
    // committed terminal state and its result.
    $reloaded = Run::query()->findOrFail($run->id);

    expect($terminal)->not->toBeNull()
        ->and($reloaded->status->isTerminal())->toBeTrue()
        ->and($reloaded->output_text)->toBe('Done.')
        ->and($reloaded->finished_at)->not->toBeNull();
});

it('6. cannot execute a resolved approval twice', function (): void {
    $this->harness->script([
        HarnessResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
        HarnessResult::text('Published.'),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $paused = $session->prompt('Publish it.');
    $approval = Approval::query()->firstOrFail();

    $paused->run->approve($approval->id, actor: $this->owner);
    $paused->run->approve($approval->id, actor: $this->owner);

    expect(Approval::query()->count())->toBe(1)
        ->and($paused->run->events()->where('type', 'approval.resolved')->count())->toBe(1);
});

it('7. does not duplicate execution when a job is delivered twice', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = Harness::coordinator()->createRun($session, 'Do it once.');

    $job = new ExecuteAgentRun($run->id, $run->version);

    $this->app->call([$job, 'handle']);
    $sequenceAfterFirst = $run->refresh()->last_event_sequence;

    // The same job arrives again after the first already finished the run.
    $this->app->call([$job, 'handle']);

    expect($run->refresh()->last_event_sequence)->toBe($sequenceAfterFirst)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($this->harness->driver()->prompts)->toHaveCount(1);
});

it('8. prevents a new step once cancellation is observed', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = Harness::coordinator()->createRun($session, 'A long job.');

    $run->cancel('Stop.');

    // Executing a cancelled run does nothing further.
    Harness::coordinator()->executeRun($run->id);

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled)
        ->and($this->harness->driver()->prompts)->toHaveCount(0);
});

it('9. refuses to checkpoint a payload containing a configured secret', function (): void {
    $store = app(AgentHarness\Laravel\Checkpoints\CheckpointStore::class);
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $leaky = new DriverCheckpoint(
        driver: 'fake',
        schemaVersion: 1,
        payload: ['conversation_id' => 'abc', 'api_key' => 'sk-live-should-never-persist'],
    );

    expect(fn () => $store->store($session, null, $leaky))
        ->toThrow(LogicException::class, 'sensitive key');
});

it('10. does not let a driver silently claim an unsupported capability', function (): void {
    $registry = app(DriverRegistry::class);

    $registry->extend('no-streaming', fn (): AgentHarness\Laravel\Contracts\HarnessDriver => new class extends AgentHarness\Laravel\Drivers\FakeDriver
    {
        public function name(): string
        {
            return 'no-streaming';
        }

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(streaming: false, approvals: false);
        }
    });

    $driver = $registry->driver('no-streaming');

    expect(fn () => $registry->requireCapability($driver, 'streaming'))
        ->toThrow(HarnessCapabilityUnsupported::class);
});

it('11. never reuses a terminal run record when retrying', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $original = $session->prompt('Do it.')->run;

    $retry = $original->retry();

    expect($retry->id)->not->toBe($original->id)
        ->and($retry->attempt)->toBe(2)
        ->and($retry->retry_of_run_id)->toBe($original->id)
        ->and($original->refresh()->status)->toBe(RunStatus::Completed);
});

it('12. keeps one participant from reaching another participant\'s session', function (): void {
    $stranger = $this->user('stranger@example.com');

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->prompt('Do it.')->run;

    expect(fn () => $session->authorizeFor($stranger))
        ->toThrow(AgentHarness\Laravel\Exceptions\RunNotAuthorized::class);

    expect(fn () => Harness::run($run->id)->authorizeFor($stranger))
        ->toThrow(AgentHarness\Laravel\Exceptions\RunNotAuthorized::class);

    expect(Harness::sessionsFor($stranger))->toBeEmpty()
        ->and(Harness::sessionsFor($this->owner))->toHaveCount(1);
});
