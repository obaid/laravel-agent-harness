<?php

declare(strict_types=1);

/**
 * The architectural invariants from docs/ARCHITECTURE.md §19.
 *
 * These are the promises the package makes. Each one gets an explicit test so a
 * regression shows up as a named failure rather than as odd behavior in
 * production.
 */

use Clutch\Laravel\Data\DriverCheckpoint;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Exceptions\CapabilityUnsupported;
use Clutch\Laravel\Exceptions\SessionBusy;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Jobs\ExecuteAgentRun;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\RunEvent;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Runtime\DriverRegistry;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\ValueObjects\DriverCapabilities;

beforeEach(function (): void {
    $this->owner = $this->user();
    $this->clutch = Clutch::fake([ClutchResult::text('Done.')]);
});

it('1. allows a session at most one active run', function (): void {
    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();

    Clutch::coordinator()->createRun($session, 'First.');

    expect(fn () => Clutch::coordinator()->createRun($session->refresh(), 'Second.'))
        ->toThrow(SessionBusy::class);

    expect(Run::query()->active()->count())->toBe(1);
});

it('2. gives a run exactly one immutable terminal state', function (): void {
    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->prompt('Do it.')->run;

    expect($run->status)->toBe(RunStatus::Completed);

    // A terminal status permits no further transitions at all.
    expect($run->status->allowedTransitions())->toBe([]);

    $terminals = $run->events()->get()->filter(fn (RunEvent $e): bool => $e->type->isTerminal());

    expect($terminals)->toHaveCount(1);
});

it('3. never repeats or decreases an event sequence within a run', function (): void {
    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->prompt('Do it.')->run;

    $sequences = $run->events()->pluck('sequence');

    expect($sequences->duplicates())->toBeEmpty()
        ->and($sequences->all())->toBe($sequences->sort()->values()->all())
        ->and($sequences->first())->toBe(1);
});

it('4. records a matching event for every lifecycle transition', function (): void {
    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->queue('Do it.');

    $types = $run->refresh()->events()->pluck('type')->map->value;

    expect($types)->toContain('run.created')
        ->toContain('run.queued')
        ->toContain('run.started')
        ->toContain('run.completed');
});

it('5. never exposes a terminal event without terminal state and result', function (): void {
    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
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
    $this->clutch->script([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
        ClutchResult::text('Published.'),
    ]);

    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $paused = $session->prompt('Publish it.');
    $approval = Approval::query()->firstOrFail();

    $paused->run->approve($approval->id, actor: $this->owner);
    $paused->run->approve($approval->id, actor: $this->owner);

    expect(Approval::query()->count())->toBe(1)
        ->and($paused->run->events()->where('type', 'approval.resolved')->count())->toBe(1);
});

it('7. does not duplicate execution when a job is delivered twice', function (): void {
    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = Clutch::coordinator()->createRun($session, 'Do it once.');

    $job = new ExecuteAgentRun($run->id, $run->version);

    $this->app->call([$job, 'handle']);
    $sequenceAfterFirst = $run->refresh()->last_event_sequence;

    // The same job arrives again after the first already finished the run.
    $this->app->call([$job, 'handle']);

    expect($run->refresh()->last_event_sequence)->toBe($sequenceAfterFirst)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($this->clutch->driver()->prompts)->toHaveCount(1);
});

it('8. prevents a new step once cancellation is observed', function (): void {
    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = Clutch::coordinator()->createRun($session, 'A long job.');

    $run->cancel('Stop.');

    // Executing a cancelled run does nothing further.
    Clutch::coordinator()->executeRun($run->id);

    expect($run->refresh()->status)->toBe(RunStatus::Cancelled)
        ->and($this->clutch->driver()->prompts)->toHaveCount(0);
});

it('9. refuses to checkpoint a payload containing a configured secret', function (): void {
    $store = app(\Clutch\Laravel\Checkpoints\CheckpointStore::class);
    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();

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

    $registry->extend('no-streaming', fn (): \Clutch\Laravel\Contracts\ClutchDriver => new class extends \Clutch\Laravel\Drivers\FakeDriver
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
        ->toThrow(CapabilityUnsupported::class);
});

it('11. never reuses a terminal run record when retrying', function (): void {
    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $original = $session->prompt('Do it.')->run;

    $retry = $original->retry();

    expect($retry->id)->not->toBe($original->id)
        ->and($retry->attempt)->toBe(2)
        ->and($retry->retry_of_run_id)->toBe($original->id)
        ->and($original->refresh()->status)->toBe(RunStatus::Completed);
});

it('12. keeps one participant from reaching another participant\'s session', function (): void {
    $stranger = $this->user('stranger@example.com');

    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->prompt('Do it.')->run;

    expect(fn () => $session->authorizeFor($stranger))
        ->toThrow(\Clutch\Laravel\Exceptions\RunNotAuthorized::class);

    expect(fn () => Clutch::run($run->id)->authorizeFor($stranger))
        ->toThrow(\Clutch\Laravel\Exceptions\RunNotAuthorized::class);

    expect(Clutch::sessionsFor($stranger))->toBeEmpty()
        ->and(Clutch::sessionsFor($this->owner))->toHaveCount(1);
});

it('13. resumes a suspended turn without repeating finished work', function (): void {
    $this->clutch->script([
        ClutchResult::text('Done.')
            ->withToolCall('first_tool', ['n' => 1], 'a')
            ->withToolCall('second_tool', ['n' => 2], 'b'),
    ]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->sliceAfterSteps(1)
        ->create();

    $run = $session->queue('Do both things.')->refresh();

    expect($run->status)->toBe(RunStatus::Completed);

    // Each tool ran once despite the turn crossing several slices.
    $calls = $run->events()->where('type', 'tool.call.requested')->get();

    expect($calls->pluck('payload.tool')->all())->toBe(['first_tool', 'second_tool'])
        ->and($run->events()->where('type', 'run.suspended')->count())->toBeGreaterThan(0);
});

it('14. never lets a blocked tool call reach the tool', function (): void {
    $session = Clutch::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = Clutch::coordinator()->createRun($session, 'Do it.');

    $ledger = new \Clutch\Laravel\Tools\ToolExecutionLedger(
        guard: new \Clutch\Laravel\Guards\LoopGuard(remindAfter: 1, blockAfter: 1),
    );

    $call = new \Clutch\Laravel\Data\ToolInvocation(
        sessionId: $session->id,
        runId: $run->id,
        toolCallId: 'call_1',
        toolName: 'charge_customer',
        arguments: ['invoice' => 1],
    );

    $sideEffects = 0;
    $execute = function () use (&$sideEffects): string {
        $sideEffects++;

        return 'charged';
    };

    $ledger->guard($call, null, $execute);
    $ledger->guard($call, null, $execute);
    $ledger->guard($call, null, $execute);

    // The guard refused the repeats outright rather than letting the side
    // effect run and discarding its result.
    expect($sideEffects)->toBe(1);
});

it('leaves no approval pending on a run that has finished', function (): void {
    $this->clutch->script([
        ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['article_id' => 1]),
    ]);

    $session = Clutch::agent(
        \Clutch\Laravel\Tests\Fixtures\Agents\PublishingAgent::class
    )->for($this->owner)->create();

    $result = $session->prompt('Publish it.');

    expect(Approval::query()->where('status', 'pending')->count())->toBe(1);

    // The reaper transitions a run straight to failed rather than going
    // through a finalizer, which is exactly the path that used to strand an
    // approval where nothing could ever resolve it.
    $result->run->forceFill([
        'status' => RunStatus::Running,
        'heartbeat_at' => now()->subHour(),
        'started_at' => now()->subHour(),
    ])->save();

    dispatch_sync(new \Clutch\Laravel\Jobs\ReapAbandonedRuns(staleAfterSeconds: 60, retry: false));

    expect($result->run->refresh()->status->isTerminal())->toBeTrue()
        ->and(Approval::query()->where('status', 'pending')->count())->toBe(0);
});
