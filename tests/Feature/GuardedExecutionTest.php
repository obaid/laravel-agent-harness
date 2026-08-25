<?php

declare(strict_types=1);

/**
 * The ledger, the guards and the spill policy only do anything if something
 * actually routes tool calls through them. Nothing did, for three releases,
 * because the driver executes tools inside Laravel AI's own loop and the
 * ledger was never in the path. These run tools the way an agent does.
 */

use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\Artifact;
use Clutch\Laravel\Models\ToolExecution;
use Clutch\Laravel\Policies\PolicyAwareTools;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\Tests\Fixtures\Tools\PublishArticle;
use Clutch\Laravel\Tests\Fixtures\Tools\SearchWeb;
use Clutch\Laravel\Tools\SpillPolicy;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    $this->owner = $this->user();
    PublishArticle::$published = [];
});

/**
 * Run a tool the way the agent does: through the policy, inside a run.
 *
 * @param  array<string, mixed>  $arguments
 */
function callThroughPolicy(object $tool, array $arguments, string $callId): string
{
    Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for(test()->owner)
        ->permissions(PermissionMode::AllowAll)
        ->create();

    $run = Clutch::coordinator()->createRun($session, 'Do it.');

    return withRunContext($session, $run, function () use ($tool, $arguments, $callId): string {
        $guarded = app(PolicyAwareTools::class)->apply([$tool])[0];

        return (string) $guarded->handle(new Request($arguments, $callId));
    });
}

it('records a tool execution when the agent calls it', function (): void {
    callThroughPolicy(new SearchWeb, ['query' => 'laravel'], 'call_1');

    // Nothing was writing to this table before the wrapper existed.
    expect(ToolExecution::query()->count())->toBe(1)
        ->and(ToolExecution::query()->first()->tool_name)->toBe('SearchWeb');
});

it('fires an idempotent side effect once across repeated calls', function (): void {
    Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->permissions(PermissionMode::AllowAll)
        ->create();

    $run = Clutch::coordinator()->createRun($session, 'Publish it.');

    withRunContext($session, $run, function (): void {
        $guarded = app(PolicyAwareTools::class)->apply([new PublishArticle])[0];

        // Three deliveries of one side effect, each with a fresh call id,
        // which is exactly what a crash and retry produce.
        foreach (['a', 'b', 'c'] as $callId) {
            $guarded->handle(new Request(['article_id' => 42], $callId));
        }
    });

    expect(PublishArticle::$published)->toBe([42]);
});

it('spills an oversized result to an artifact', function (): void {
    Storage::fake('artifacts');
    config()->set('clutch.artifacts.disk', 'artifacts');
    app()->instance(SpillPolicy::class, new SpillPolicy(thresholdBytes: 40, previewBytes: 20));
    app()->forgetInstance(\Clutch\Laravel\Tools\ToolExecutionLedger::class);

    $result = callThroughPolicy(new \Clutch\Laravel\Tests\Fixtures\Tools\Chatty, [], 'call_1');

    expect($result)->toContain('Output truncated')
        ->and(strlen($result))->toBeLessThan(2000)
        ->and(Artifact::query()->count())->toBe(1);
});

it('refuses a tool that has gone round in circles', function (): void {
    app()->instance(\Clutch\Laravel\Guards\LoopGuard::class, new \Clutch\Laravel\Guards\LoopGuard(
        remindAfter: 1, blockAfter: 2,
    ));
    app()->forgetInstance(\Clutch\Laravel\Tools\ToolExecutionLedger::class);

    Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->owner)
        ->permissions(PermissionMode::AllowAll)
        ->create();

    $run = Clutch::coordinator()->createRun($session, 'Search.');

    $results = withRunContext($session, $run, function (): array {
        $guarded = app(PolicyAwareTools::class)->apply([new SearchWeb])[0];

        $out = [];
        foreach (range(1, 4) as $i) {
            $out[] = (string) $guarded->handle(new Request(['query' => 'same'], "call_{$i}"));
        }

        return $out;
    });

    // The first calls run; past the threshold the refusal comes back as the
    // result, which is what tells a stuck agent to try something else.
    expect($results[0])->toContain('results for same')
        ->and(end($results))->toContain('identical');
});
