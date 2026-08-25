<?php

declare(strict_types=1);

use Clutch\Laravel\Artifacts\ArtifactRegistrar;
use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Policies\PolicyAwareTools;
use Clutch\Laravel\Runtime\CancellationSignal;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Runtime\RunContext;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\Tests\Fixtures\Tools\DraftEmail;
use Clutch\Laravel\Tests\Fixtures\Tools\PublishArticle;
use Clutch\Laravel\Tests\Fixtures\Tools\SearchWeb;

/**
 * Run a callback inside a real harness run context for the given mode.
 */
function withinRun(PermissionMode $mode, Closure $callback): mixed
{
    $owner = test()->user();

    Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)->for($owner)->permissions($mode)->create();
    $run = Clutch::coordinator()->createRun($session, 'Do it.');

    $context = new RunContext(
        session: $session,
        run: $run,
        artifacts: new ArtifactRegistrar($run, app(\Clutch\Laravel\Artifacts\ArtifactManager::class)),
        cancellation: CancellationSignal::never(),
        logger: app('log'),
        redactor: app(\Clutch\Laravel\Runtime\Redactor::class),
    );

    return $context->scope($callback);
}

beforeEach(function (): void {
    config()->set('clutch.permissions.tools', [
        'draft_email' => 'reversible',
        'publish_article' => 'irreversible',
    ]);
});

/** Ask a tool whether it would pause, in Laravel AI's terms. */
function wouldPause(object $tool): bool
{
    return $tool->shouldRequestApproval(new Laravel\Ai\Tools\Request) !== null;
}

it('passes tools through untouched outside a harness run', function (): void {
    $tools = app(PolicyAwareTools::class)->apply([new SearchWeb, new PublishArticle]);

    expect($tools)->toHaveCount(2);
});

it('wraps every tool it lets through, so the ledger actually runs', function (): void {
    $tools = withinRun(PermissionMode::AllowAll, fn (): array => app(PolicyAwareTools::class)
        ->apply([new SearchWeb, new PublishArticle]));

    // Laravel AI executes tools inside its own loop, so the wrapper is the only
    // place the ledger, guards and spill policy can sit.
    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(\Clutch\Laravel\Tools\GuardedTool::class);
    }

    // And the wrapper is transparent: the model still sees the real name.
    expect(app(PolicyAwareTools::class)->nameOf($tools[0]))->toBe('SearchWeb');
});

it('keeps an approvable tool approvable through the wrapper', function (): void {
    config()->set('clutch.permissions.tools', ['publish_article' => 'irreversible']);

    $tools = withinRun(PermissionMode::ApproveSensitive, fn (): array => app(PolicyAwareTools::class)
        ->apply([new PublishArticle]));

    // Laravel AI decides whether a call pauses by checking instanceof
    // Approvable, so a wrapper that dropped it would silently kill approvals.
    expect($tools[0])->toBeInstanceOf(Laravel\Ai\Contracts\Approvable::class)
        ->and(wouldPause($tools[0]))->toBeTrue();
});

it('lets safe tools through and marks sensitive ones for approval', function (): void {
    $tools = withinRun(PermissionMode::ApproveSensitive, function (): array {
        return app(PolicyAwareTools::class)->apply([new SearchWeb, new DraftEmail, new PublishArticle]);
    });

    expect($tools)->toHaveCount(3);

    [, $draft, $publish] = $tools;

    // A reversible tool runs freely; the irreversible one now pauses the run.
    expect(wouldPause($draft))->toBeFalse()
        ->and(wouldPause($publish))->toBeTrue();
});

it('withholds a denied tool from the agent entirely', function (): void {
    $tools = withinRun(PermissionMode::DenyByDefault, function (): array {
        return app(PolicyAwareTools::class)->apply([new SearchWeb, new DraftEmail, new PublishArticle]);
    });

    // The model is never told the denied tool exists; refusing after the fact
    // would still have exposed the capability.
    expect($tools)->toHaveCount(1)
        ->and($tools[0]->inner())->toBeInstanceOf(SearchWeb::class);
});

it('requires approval for every state-changing tool in approve-all mode', function (): void {
    $tools = withinRun(PermissionMode::ApproveAll, function (): array {
        return app(PolicyAwareTools::class)->apply([new SearchWeb, new DraftEmail, new PublishArticle]);
    });

    [, $draft, $publish] = $tools;

    // Even the reversible tool now pauses, which is the whole point of the mode.
    expect(wouldPause($draft))->toBeTrue()
        ->and(wouldPause($publish))->toBeTrue();
});

it('adds no harness approval in allow-all mode', function (): void {
    $tools = withinRun(PermissionMode::AllowAll, function (): array {
        return app(PolicyAwareTools::class)->apply([new SearchWeb, new DraftEmail, new PublishArticle]);
    });

    expect($tools)->toHaveCount(3)
        ->and(wouldPause($tools[1]))->toBeFalse();
});

it('leaves a tool\'s own approval requirement alone', function (): void {
    // A tool that asks for approval itself is making a Laravel AI declaration,
    // not a harness one. Allow-all relaxes the harness policy; it does not
    // override what the tool author decided.
    $tools = withinRun(PermissionMode::AllowAll, function (): array {
        return app(PolicyAwareTools::class)->apply([
            (new PublishArticle)->requireApproval('Publishing is irreversible.'),
        ]);
    });

    expect(wouldPause($tools[0]))->toBeTrue();
});

it('honors an always-allow list even under deny-by-default', function (): void {
    config()->set('clutch.permissions.always_allow', ['publish_article']);

    $tools = withinRun(PermissionMode::DenyByDefault, function (): array {
        return app(PolicyAwareTools::class)->apply([new SearchWeb, new PublishArticle]);
    });

    expect($tools)->toHaveCount(2);
});

it('uses the same tool name as Laravel AI', function (): void {
    $resolver = app(PolicyAwareTools::class);

    // One name reaches the model, the approval record, the events and the
    // ledger, so an operator reading any of them sees the same thing.
    expect($resolver->nameOf(new SearchWeb))->toBe('SearchWeb')
        ->and($resolver->nameOf(new PublishArticle))->toBe('PublishArticle');
});

it('accepts either spelling in configuration', function (): void {
    // snake_case reads better in a config file, so both resolve.
    config()->set('clutch.permissions.tools', ['search_web' => 'read_only']);
    app()->forgetInstance(\Clutch\Laravel\Policies\PolicyEngine::class);

    expect(app(\Clutch\Laravel\Policies\PolicyEngine::class)->sensitivityOf('SearchWeb'))
        ->toBe(\Clutch\Laravel\Enums\ToolSensitivity::ReadOnly);

    config()->set('clutch.permissions.tools', ['SearchWeb' => 'read_only']);
    app()->forgetInstance(\Clutch\Laravel\Policies\PolicyEngine::class);

    expect(app(\Clutch\Laravel\Policies\PolicyEngine::class)->sensitivityOf('SearchWeb'))
        ->toBe(\Clutch\Laravel\Enums\ToolSensitivity::ReadOnly);
});

it('exposes the permission mode to tools through the run context', function (): void {
    $mode = withinRun(PermissionMode::ApproveAll, fn (): PermissionMode => RunContext::current()->permissionMode());

    expect($mode)->toBe(PermissionMode::ApproveAll);
});

it('is reachable through the facade', function (): void {
    $tools = withinRun(PermissionMode::DenyByDefault, fn (): array => Clutch::policy([new SearchWeb, new PublishArticle]));

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->inner())->toBeInstanceOf(SearchWeb::class);
});
