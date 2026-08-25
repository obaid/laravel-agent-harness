<?php

declare(strict_types=1);

use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\ToolExecution;
use Clutch\Laravel\Tests\Fixtures\Agents\GuardedAgent;
use Clutch\Laravel\Tests\Fixtures\Agents\PublishingAgent;
use Clutch\Laravel\Tests\Fixtures\Tools\PublishArticle;
use Clutch\Laravel\Tests\Fixtures\Tools\SearchWeb;
use Clutch\Laravel\Tools\GuardedApprovableTool;
use Clutch\Laravel\Tools\GuardedTool;
use Laravel\Ai\Contracts\Approvable;

/**
 * Whether the protections are actually reached, rather than merely present.
 *
 * A whole release shipped with the tool ledger, the loop guards, the deadlines
 * and the spill policy all working perfectly and none of them ever called.
 * Every one of those had passing tests, because each test invoked the
 * component directly. Nothing asserted that production code did.
 *
 * These do. They are deliberately about reachability, not behaviour: the
 * behaviour is tested elsewhere, and was never the thing that broke.
 */
beforeEach(function (): void {
    $this->owner = $this->user();
});

it('wraps every tool an agent exposes, inside a run', function (): void {
    inARun(function (): void {
        foreach (Clutch::policy([new SearchWeb, new PublishArticle]) as $tool) {
            expect($tool)->toBeInstanceOf(GuardedTool::class,
                'A tool that is not wrapped gets no ledger, no guards, no deadline and no spill.');
        }
    });
});

it('leaves tools alone outside a run, where there is nothing to scope them to', function (): void {
    // Deliberate: with no session there is no permission mode, no budget and
    // no ledger to write to. Pinned here so it stays a decision rather than
    // drifting into an accident.
    foreach (Clutch::policy([new SearchWeb]) as $tool) {
        expect($tool)->not->toBeInstanceOf(GuardedTool::class);
    }
});

it('keeps an approvable tool approvable once wrapped', function (): void {
    // Laravel AI decides whether a call pauses by checking this interface. A
    // wrapper that drops it silently disables every approval in the app.
    inARun(function (): void {
        $wrapped = Clutch::policy([new PublishArticle])[0];

        expect($wrapped)->toBeInstanceOf(Approvable::class)
            ->toBeInstanceOf(GuardedApprovableTool::class);
    });
});

it('keeps a tool answering to its own name once wrapped', function (): void {
    // Approvals, events and the permission map all key off the name. A
    // wrapper that renames a tool breaks all three at once.
    inARun(function (): void {
        expect(Clutch::policy([new SearchWeb])[0]->name())->toBe(
            Laravel\Ai\Tools\ToolNameResolver::resolve(new SearchWeb),
        );
    });
});

it('hands the driver guarded tools when it asks an agent for them', function (): void {
    // The seam that broke: the driver calls tools() on the agent, and whatever
    // comes back is what actually runs. If that is not wrapped, every
    // protection is bypassed no matter how well each one works alone.
    inARun(function (): void {
        $tools = iterator_to_array((new GuardedAgent)->tools(), false);

        expect($tools)->not->toBeEmpty();

        foreach ($tools as $tool) {
            expect($tool)->toBeInstanceOf(GuardedTool::class,
                GuardedAgent::class.' exposes a tool the harness never sees.');
        }
    });
});

it('writes to the ledger when a guarded tool actually runs', function (): void {
    inARun(function (): void {
        $tool = Clutch::policy([new PublishArticle])[0];

        $tool->handle(new Laravel\Ai\Tools\Request(['article_id' => 7], 'call_1'));
    });

    // The row is the proof the wrapper is more than decoration.
    expect(ToolExecution::query()->count())
        ->toBeGreaterThan(0, 'A tool ran without the ledger seeing it.');
});

it('exposes every guard the config documents', function (): void {
    // A config key nothing reads is a promise the package does not keep.
    $guards = (array) config('clutch.guards');

    foreach ([
        'enabled', 'remind_after_repeats', 'block_after_repeats',
        'tool_timeout_seconds', 'tool_timeouts',
    ] as $key) {
        // Present, not necessarily set: a null timeout means no global
        // deadline, which is a legitimate default. A missing key is not.
        expect(array_key_exists($key, $guards))->toBeTrue(
            "clutch.guards.{$key} is documented but absent from the shipped config.",
        );
    }
});

it('resolves every service the container is asked for by name', function (): void {
    // Each of these is constructed somewhere with app(). A binding that has
    // gone stale fails here rather than at the moment a run needs it.
    $services = [
        \Clutch\Laravel\Runtime\RunCoordinator::class,
        \Clutch\Laravel\Runtime\DriverRegistry::class,
        \Clutch\Laravel\Runtime\EventStore::class,
        \Clutch\Laravel\Approvals\ApprovalBroker::class,
        \Clutch\Laravel\Artifacts\ArtifactManager::class,
        \Clutch\Laravel\Checkpoints\CheckpointStore::class,
        \Clutch\Laravel\Tools\ToolExecutionLedger::class,
        \Clutch\Laravel\Tools\SpillPolicy::class,
        \Clutch\Laravel\Guards\ToolDeadline::class,
        \Clutch\Laravel\Compaction\Compactor::class,
        \Clutch\Laravel\Leases\LeaseManager::class,
        \Clutch\Laravel\Workflows\WorkflowRunner::class,
        \Clutch\Laravel\Workflows\WorkflowAgentCaller::class,
        \Clutch\Laravel\Workflows\WorkflowDriver::class,
    ];

    foreach ($services as $service) {
        expect(app($service))->toBeInstanceOf($service);
    }
});

it('registers every console command the docs tell people to run', function (): void {
    $registered = array_keys(app(Illuminate\Contracts\Console\Kernel::class)->all());

    foreach ([
        'clutch:events', 'clutch:sessions', 'clutch:run', 'clutch:reap',
        'clutch:retry', 'clutch:cancel', 'clutch:prune',
        'make:clutch-agent', 'make:clutch-workflow',
    ] as $command) {
        expect($registered)->toContain($command);
    }
});

it('ships a stub for every generator it registers', function (): void {
    foreach (['clutch-agent', 'clutch-workflow'] as $stub) {
        expect(file_exists(__DIR__."/../../stubs/{$stub}.stub"))
            ->toBeTrue("The {$stub} generator has no stub to generate from.");
    }
});

it('says so when an agent returns tools the harness cannot see', function (): void {
    config()->set('clutch.default_driver', 'laravel-ai');

    // PublishingAgent returns its tools directly, which is the mistake. There
    // is no seam to enforce this from, because Laravel AI reads tools() itself,
    // so the least the harness can do is not let it happen quietly.
    PublishingAgent::fake(['Published.']);

    Illuminate\Support\Facades\Log::spy();

    Clutch::agent(PublishingAgent::class)->for($this->owner)->create()->prompt('Publish it.');

    Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Clutch::policy')
            && ($context['agent'] ?? null) === PublishingAgent::class);
});

it('stays quiet for an agent that is wired correctly', function (): void {
    config()->set('clutch.default_driver', 'laravel-ai');

    GuardedAgent::fake(['Done.']);

    Illuminate\Support\Facades\Log::spy();

    Clutch::agent(GuardedAgent::class)->for($this->owner)->create()->prompt('Search then publish.');

    Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
});

/**
 * Run a closure as though a real run were in progress.
 *
 * Most of the harness only engages inside a run, so a wiring test that does
 * not open one is testing the wrong condition.
 */
function inARun(Closure $work): mixed
{
    Clutch::fake([\Clutch\Laravel\Runtime\ClutchResult::text('ok')]);

    $session = Clutch::agent(PublishingAgent::class)
        ->for(test()->owner)
        ->permissions(PermissionMode::ApproveSensitive)
        ->create();

    $run = Clutch::coordinator()->createRun($session, 'Do it.');

    return withRunContext($session, $run, $work);
}
