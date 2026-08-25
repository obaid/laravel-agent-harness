<?php

declare(strict_types=1);

use Clutch\Laravel\Artifacts\ArtifactManager;
use Clutch\Laravel\Artifacts\ArtifactRegistrar;
use Clutch\Laravel\Data\ToolInvocation;
use Clutch\Laravel\Exceptions\ToolTimedOut;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Guards\LoopGuard;
use Clutch\Laravel\Guards\ToolDeadline;
use Clutch\Laravel\Models\Artifact;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\Tools\SpillPolicy;
use Clutch\Laravel\Tools\ToolExecutionLedger;
use Illuminate\Support\Facades\Storage;

function callTo(string $tool, array $arguments = []): ToolInvocation
{
    return new ToolInvocation(
        sessionId: 'ses_1',
        runId: 'run_1',
        toolCallId: 'call_'.md5(serialize($arguments)),
        toolName: $tool,
        arguments: $arguments,
    );
}

// Loop guards ---------------------------------------------------------------

it('lets ordinary repetition through', function (): void {
    $guard = new LoopGuard(remindAfter: 3, blockAfter: 8);

    // Re-reading a file you just wrote is normal, not a loop.
    expect($guard->inspect(callTo('read', ['path' => 'a.txt']))->outcome)->toBe('proceed')
        ->and($guard->inspect(callTo('read', ['path' => 'a.txt']))->outcome)->toBe('proceed');
});

it('reminds the model once a call is clearly repeating', function (): void {
    $guard = new LoopGuard(remindAfter: 2, blockAfter: 8);

    $guard->inspect(callTo('search', ['q' => 'x']));
    $guard->inspect(callTo('search', ['q' => 'x']));

    $decision = $guard->inspect(callTo('search', ['q' => 'x']));

    expect($decision->isReminder())->toBeTrue()
        ->and($decision->allowsExecution())->toBeTrue()
        ->and($decision->message)->toContain('3 times');
});

it('refuses a call that has become pathological', function (): void {
    $guard = new LoopGuard(remindAfter: 2, blockAfter: 4);

    for ($i = 0; $i < 4; $i++) {
        $guard->inspect(callTo('search', ['q' => 'x']));
    }

    $decision = $guard->inspect(callTo('search', ['q' => 'x']));

    expect($decision->isBlocked())->toBeTrue()
        ->and($decision->allowsExecution())->toBeFalse()
        ->and($decision->message)->toContain('will not change');
});

it('counts different arguments separately', function (): void {
    $guard = new LoopGuard(remindAfter: 1, blockAfter: 3);

    $guard->inspect(callTo('search', ['q' => 'a']));
    $guard->inspect(callTo('search', ['q' => 'a']));

    // A different query is different work, not a repeat.
    expect($guard->inspect(callTo('search', ['q' => 'b']))->outcome)->toBe('proceed')
        ->and($guard->timesSeen(callTo('search', ['q' => 'a'])))->toBe(2);
});

it('can be turned off', function (): void {
    $guard = new LoopGuard(remindAfter: 1, blockAfter: 1, enabled: false);

    for ($i = 0; $i < 20; $i++) {
        expect($guard->inspect(callTo('search', ['q' => 'x']))->outcome)->toBe('proceed');
    }
});

it('returns the refusal to the model instead of running the tool', function (): void {
    Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)->for($this->user())->create();
    $run = Clutch::coordinator()->createRun($session, 'Do it.');

    $call = new ToolInvocation(
        sessionId: $session->id,
        runId: $run->id,
        toolCallId: 'call_1',
        toolName: 'search',
        arguments: ['q' => 'x'],
    );

    $ledger = new ToolExecutionLedger(guard: new LoopGuard(remindAfter: 1, blockAfter: 1));
    $ran = 0;

    $ledger->guard($call, null, function () use (&$ran): string {
        $ran++;

        return 'first';
    });

    $second = $ledger->guard($call, null, function () use (&$ran): string {
        $ran++;

        return 'second';
    });

    // The tool ran once. The second call got the refusal as its result, which
    // is what tells the agent to stop rather than leaving it starved.
    expect($ran)->toBe(1)
        ->and($second)->toContain('identical');
});

// Tool deadlines -------------------------------------------------------------

it('applies no deadline when none is configured', function (): void {
    $deadline = new ToolDeadline;

    expect($deadline->secondsFor('anything'))->toBeNull()
        ->and($deadline->guard(callTo('slow'), fn (): string => 'done'))->toBe('done');
});

it('prefers a per-tool deadline over the default', function (): void {
    $deadline = new ToolDeadline(defaultSeconds: 30, perTool: ['scrape' => 120]);

    expect($deadline->secondsFor('scrape'))->toBe(120)
        ->and($deadline->secondsFor('search'))->toBe(30);
});

it('reports a tool that ran past its deadline', function (): void {
    $deadline = new ToolDeadline(defaultSeconds: 1);

    expect(fn () => $deadline->guard(callTo('slow'), function (): string {
        usleep(1_200_000);

        return 'too late';
    }))->toThrow(ToolTimedOut::class, 'deadline');
});

// Spill ----------------------------------------------------------------------

it('leaves a small result alone', function (): void {
    $policy = new SpillPolicy(thresholdBytes: 1000);

    expect($policy->shouldSpill('short'))->toBeFalse();
});

it('spills an oversized result to an artifact and previews it', function (): void {
    Storage::fake('artifacts');
    config()->set('clutch.artifacts.disk', 'artifacts');

    Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)->for($this->user())->create();
    $run = Clutch::coordinator()->createRun($session, 'Do it.');

    $registrar = new ArtifactRegistrar($run, app(ArtifactManager::class));
    $policy = new SpillPolicy(thresholdBytes: 100, previewBytes: 60);

    $big = str_repeat("line of scraped output\n", 200);

    expect($policy->shouldSpill($big))->toBeTrue();

    $spilled = $policy->spill($registrar, 'scrape_page', 'call_9', $big);

    // The model gets a bounded excerpt plus a way to reach the rest.
    expect((string) $spilled)
        ->toContain('line of scraped output')
        ->toContain('Output truncated')
        ->toContain($spilled->artifactId)
        ->and(strlen((string) $spilled))->toBeLessThan(strlen($big));

    // The whole thing is on disk, attached to the run.
    $artifact = Artifact::query()->firstOrFail();

    expect($artifact->run_id)->toBe($run->id)
        ->and($artifact->metadata['spilled'])->toBeTrue()
        ->and($artifact->metadata['tool'])->toBe('scrape_page')
        ->and($artifact->contents())->toBe($big);
});

it('routes an oversized result through the ledger', function (): void {
    Storage::fake('artifacts');
    config()->set('clutch.artifacts.disk', 'artifacts');

    Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)->for($this->user())->create();
    $run = Clutch::coordinator()->createRun($session, 'Do it.');

    $ledger = new ToolExecutionLedger(spill: new SpillPolicy(thresholdBytes: 50, previewBytes: 30));
    $registrar = new ArtifactRegistrar($run, app(ArtifactManager::class));

    $result = $ledger->spillIfOversized(
        callTo('scrape_page', ['url' => 'x']),
        str_repeat('x', 500),
        $registrar,
    );

    expect($result)->toContain('Output truncated')
        ->and(Artifact::query()->count())->toBe(1);
});
