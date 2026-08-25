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
        ->and($tools[0])->toBeInstanceOf(SearchWeb::class);
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

it('derives the tool name Laravel AI uses', function (): void {
    $resolver = app(PolicyAwareTools::class);

    expect($resolver->nameOf(new SearchWeb))->toBe('search_web')
        ->and($resolver->nameOf(new PublishArticle))->toBe('publish_article');
});

it('exposes the permission mode to tools through the run context', function (): void {
    $mode = withinRun(PermissionMode::ApproveAll, fn (): PermissionMode => RunContext::current()->permissionMode());

    expect($mode)->toBe(PermissionMode::ApproveAll);
});

it('is reachable through the facade', function (): void {
    $tools = withinRun(PermissionMode::DenyByDefault, fn (): array => Clutch::policy([new SearchWeb, new PublishArticle]));

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(SearchWeb::class);
});
