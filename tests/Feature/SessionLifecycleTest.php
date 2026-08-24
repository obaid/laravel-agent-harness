<?php

declare(strict_types=1);

use AgentHarness\Laravel\Enums\PermissionMode;
use AgentHarness\Laravel\Enums\RunStatus;
use AgentHarness\Laravel\Enums\SessionStatus;
use AgentHarness\Laravel\Exceptions\RunNotAuthorized;
use AgentHarness\Laravel\Exceptions\SessionBusy;
use AgentHarness\Laravel\Facades\Harness;
use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Models\Session;
use AgentHarness\Laravel\Runtime\HarnessResult;
use AgentHarness\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use AgentHarness\Laravel\ValueObjects\RunBudget;

beforeEach(function (): void {
    $this->harness = Harness::fake([
        'Research our three closest competitors' => HarnessResult::text('Their weakest flank is onboarding.'),
    ]);

    $this->owner = $this->user();
});

it('creates a durable session that is ready to accept work', function (): void {
    $session = Harness::agent(ResearchAgent::class)
        ->for($this->owner)
        ->name('Competitor research')
        ->create();

    expect($session->id)->toStartWith('ses_')
        ->and($session->status)->toBe(SessionStatus::Ready)
        ->and($session->agent_class)->toBe(ResearchAgent::class)
        ->and($session->name)->toBe('Competitor research')
        ->and($session->participant_id)->toBe((string) $this->owner->id)
        ->and($session->active_run_id)->toBeNull();

    // A between-turn checkpoint exists from the moment the session is ready,
    // so the very first run has something to restore.
    expect($session->checkpoints()->count())->toBe(1);
});

it('runs a prompt synchronously and returns its terminal result', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $result = $session->prompt('Research our three closest competitors and recommend a wedge.');

    expect($result)->toBeInstanceOf(HarnessResult::class)
        ->and($result->isCompleted())->toBeTrue()
        ->and($result->text)->toBe('Their weakest flank is onboarding.')
        ->and($result->run->status)->toBe(RunStatus::Completed)
        ->and($result->run->finished_at)->not->toBeNull();

    // The active slot is freed by the same write that made the run terminal.
    expect($session->refresh()->active_run_id)->toBeNull()
        ->and($session->status)->toBe(SessionStatus::Ready);
});

it('records an ordered, gap-free event history for a run', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $result = $session->prompt('Research our three closest competitors.');

    $events = $result->run->events()->get();

    expect($events->pluck('sequence')->all())
        ->toBe(range(1, $events->count()));

    expect($events->pluck('type')->map->value)
        ->toContain('run.created')
        ->toContain('run.started')
        ->toContain('text.delta')
        ->toContain('run.completed');

    // A terminal event is last, and there is exactly one.
    expect($events->last()->type->isTerminal())->toBeTrue()
        ->and($events->filter(fn ($e) => $e->type->isTerminal())->count())->toBe(1);
});

it('carries context across sequential runs in the same session', function (): void {
    $this->harness->script([
        HarnessResult::text('First answer.'),
        HarnessResult::text('Second answer, building on the first.'),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $first = $session->prompt('Research our competitors.');
    $second = $session->prompt('Turn that into a one-page memo.');

    expect($first->text)->toBe('First answer.')
        ->and($second->text)->toBe('Second answer, building on the first.');

    // Both runs belong to one session, and each got its own run record.
    expect($session->runs()->count())->toBe(2)
        ->and($first->run->id)->not->toBe($second->run->id);

    // The driver's conversation survived between the turns.
    expect($session->refresh()->conversation_id)->not->toBeNull();
});

it('refuses a second concurrent run in the same session', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    // Occupy the slot without finishing it.
    Harness::coordinator()->createRun($session, 'The first run.');

    expect(fn () => $session->refresh()->prompt('The second run.'))
        ->toThrow(SessionBusy::class);
});

it('scopes sessions to their participant', function (): void {
    $stranger = $this->user('stranger@example.com');

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    expect($session->authorizeFor($this->owner))->toBeInstanceOf(Session::class);

    expect(fn () => $session->authorizeFor($stranger))
        ->toThrow(RunNotAuthorized::class);
});

it('applies the most restrictive budget across configuration and session', function (): void {
    config()->set('agent-harness.budgets.max_steps', 50);

    $session = Harness::agent(ResearchAgent::class)
        ->for($this->owner)
        ->budget(new RunBudget(maxSteps: 5, maxTokens: 1_000))
        ->create();

    $budget = app(AgentHarness\Laravel\Budgets\BudgetManager::class)->effectiveBudget($session);

    expect($budget->maxSteps)->toBe(5)
        ->and($budget->maxTokens)->toBe(1_000)
        ->and($budget->maxToolCalls)->toBe(100);
});

it('stores the permission mode chosen for the session', function (): void {
    $session = Harness::agent(ResearchAgent::class)
        ->for($this->owner)
        ->permissions(PermissionMode::ApproveAll)
        ->create();

    expect($session->permission_mode)->toBe(PermissionMode::ApproveAll);
});

it('builds sessions immutably so a partial builder can be reused', function (): void {
    $base = Harness::agent(ResearchAgent::class)->for($this->owner);

    $research = $base->name('Research')->create();
    $memo = $base->name('Memo')->create();

    expect($research->name)->toBe('Research')
        ->and($memo->name)->toBe('Memo')
        ->and($research->id)->not->toBe($memo->id);
});

it('queues a run and reports its identifier immediately', function (): void {
    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $run = $session->queue('Analyze every page on our website.');

    expect($run)->toBeInstanceOf(Run::class)
        ->and($run->id)->toStartWith('run_');

    $this->harness->assertRunQueued('Analyze every page');
    $this->harness->assertRunCompleted();
});
