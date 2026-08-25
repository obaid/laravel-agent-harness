<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Jobs\ReapAbandonedRuns;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\PublishingAgent;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\ValueObjects\BudgetUsage;
use Clutch\Laravel\ValueObjects\RunBudget;

/**
 * What must be true of a finished run, whichever way it finished.
 *
 * These exist because of a real bug: every finalizer cancelled pending
 * approvals, and the reaper, which transitions a run directly, did not. The
 * rule was right in five places and missing in one, and no test noticed
 * because each test only ever exercised the path it was about.
 *
 * So this asserts the rules against every route to a terminal state instead.
 * A new route that forgets one fails here by construction.
 */
beforeEach(function (): void {
    $this->owner = $this->user();
});

/**
 * Every way a run can finish, as a name and a closure that gets it there.
 *
 * @return array<string, array{0: Closure(object): Run}>
 */
dataset('terminal paths', [
    'completed normally' => [function (object $test): Run {
        Clutch::fake([ClutchResult::text('Done.')]);
        $session = Clutch::agent(ResearchAgent::class)->for($test->owner)->create();

        return $session->prompt('Do it.')->run;
    }],

    'failed in the driver' => [function (object $test): Run {
        Clutch::fake([ClutchResult::failure('The provider fell over.')]);
        $session = Clutch::agent(ResearchAgent::class)->for($test->owner)->create();

        return $session->prompt('Do it.')->run;
    }],

    'cancelled while queued' => [function (object $test): Run {
        Clutch::fake([ClutchResult::text('Done.')]);
        $session = Clutch::agent(ResearchAgent::class)->for($test->owner)->create();
        $run = Clutch::coordinator()->createRun($session, 'Do it.');

        return Clutch::coordinator()->requestCancellation($run, 'changed my mind');
    }],

    'stopped by its budget' => [function (object $test): Run {
        Clutch::fake([ClutchResult::text('Done.')]);
        $session = Clutch::agent(ResearchAgent::class)
            ->for($test->owner)
            ->budget(new RunBudget(maxTokens: 100))
            ->create();

        $run = Clutch::coordinator()->createRun($session, 'Expensive.');
        $run->forceFill(['usage' => (new BudgetUsage(promptTokens: 120))->toArray()])->save();

        return Clutch::coordinator()->executeRun($run->id);
    }],

    'reaped after its worker vanished' => [function (object $test): Run {
        Clutch::fake([
            ClutchResult::awaitingApproval(tool: 'publish_article', arguments: ['id' => 1]),
        ]);
        $session = Clutch::agent(PublishingAgent::class)->for($test->owner)->create();
        $run = $session->prompt('Publish it.')->run;

        // The state a SIGKILL leaves behind.
        $run->forceFill([
            'status' => RunStatus::Running,
            'heartbeat_at' => now()->subHour(),
            'started_at' => now()->subHour(),
        ])->save();

        dispatch_sync(new ReapAbandonedRuns(staleAfterSeconds: 60, retry: false));

        return $run->refresh();
    }],
]);

it('leaves no approval pending on a finished run', function (Closure $reach): void {
    $run = $reach($this);

    expect($run->refresh()->status->isTerminal())->toBeTrue();

    expect(Approval::query()->where('run_id', $run->id)->where('status', 'pending')->count())
        ->toBe(0, 'A finished run cannot have an approval nobody can resolve.');
})->with('terminal paths');

it('frees the session slot when a run finishes', function (Closure $reach): void {
    $run = $reach($this);

    $session = Session::query()->findOrFail($run->refresh()->session_id);

    expect($session->active_run_id)
        ->not->toBe($run->id, 'A finished run must not still hold its session busy.');
})->with('terminal paths');

it('stamps a finished run with when it finished', function (Closure $reach): void {
    $run = $reach($this);

    expect($run->refresh()->finished_at)->not->toBeNull();
})->with('terminal paths');

it('records a terminal event for a finished run', function (Closure $reach): void {
    $run = $reach($this);

    $terminal = ['run.completed', 'run.failed', 'run.cancelled', 'run.budget_exceeded'];

    expect($run->refresh()->events()->whereIn('type', $terminal)->count())
        ->toBeGreaterThan(0, 'A finished run must say so in its own history.');
})->with('terminal paths');

it('leaves the session ready for another run', function (Closure $reach): void {
    $run = $reach($this);

    $session = Session::query()->findOrFail($run->refresh()->session_id);

    // Whatever happened, the session must not be stuck in a working state.
    expect($session->status->value)
        ->not->toBeIn(['running', 'awaiting_approval'], 'A session cannot be left mid-turn.');
})->with('terminal paths');

it('never moves a finished run again', function (Closure $reach): void {
    $run = $reach($this);
    $status = $run->refresh()->status;

    // Every terminal status is a dead end. If one is not, a late worker could
    // reopen a run whose result has already been reported.
    foreach (RunStatus::cases() as $next) {
        expect($status->canTransitionTo($next))
            ->toBeFalse("[{$status->value}] must not be able to become [{$next->value}].");
    }
})->with('terminal paths');
