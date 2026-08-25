<?php

declare(strict_types=1);

use Clutch\Laravel\Compaction\CompactionPolicy;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Policies\PolicyAwareTools;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use Clutch\Laravel\Tests\Fixtures\Tools\DraftEmail;
use Clutch\Laravel\Tests\Fixtures\Tools\PublishArticle;
use Clutch\Laravel\Tests\Fixtures\Tools\SearchWeb;
use Clutch\Laravel\ValueObjects\BudgetUsage;

// Compaction ------------------------------------------------------------------

it('does nothing while it is switched off', function (): void {
    $policy = new CompactionPolicy(enabled: false);

    expect($policy->shouldCompact(new BudgetUsage(promptTokens: 999_999), 1000, 50))->toBeFalse();
});

it('waits until the context is actually large', function (): void {
    $policy = new CompactionPolicy(triggerAtFraction: 0.7, enabled: true);

    expect($policy->shouldCompact(new BudgetUsage(promptTokens: 600), 1000, 50))->toBeFalse()
        ->and($policy->shouldCompact(new BudgetUsage(promptTokens: 700), 1000, 50))->toBeTrue();
});

it('leaves a short conversation alone whatever the token count', function (): void {
    $policy = new CompactionPolicy(keepFirst: 2, keepRecent: 8, enabled: true);

    // Summarizing five messages costs a model call and saves nothing.
    expect($policy->shouldCompact(new BudgetUsage(promptTokens: 999_999), 1000, 5))->toBeFalse();
});

it('needs a token budget to measure against', function (): void {
    $policy = new CompactionPolicy(enabled: true);

    expect($policy->shouldCompact(new BudgetUsage(promptTokens: 999_999), null, 50))->toBeFalse();
});

it('keeps the task at the start and the state at the end', function (): void {
    $policy = new CompactionPolicy(keepFirst: 2, keepRecent: 3, enabled: true);

    $messages = range(1, 10);

    $partition = $policy->partition($messages);

    expect($partition['head'])->toBe([1, 2])
        ->and($partition['middle'])->toBe([3, 4, 5, 6, 7])
        ->and($partition['tail'])->toBe([8, 9, 10]);
});

it('summarizes nothing when there is no middle to summarize', function (): void {
    $policy = new CompactionPolicy(keepFirst: 2, keepRecent: 3, enabled: true);

    $partition = $policy->partition([1, 2, 3]);

    expect($partition['middle'])->toBe([])
        ->and($partition['head'])->toBe([1, 2, 3]);
});

// Per-session tool filtering ---------------------------------------------------

it('withholds everything outside a session allow list', function (): void {
    Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->user())
        ->onlyTools(['search_web'])
        ->create();

    $tools = withinSession($session, fn (): array => app(PolicyAwareTools::class)
        ->apply([new SearchWeb, new DraftEmail, new PublishArticle]));

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->inner())->toBeInstanceOf(SearchWeb::class);
});

it('withholds exactly what a session denies', function (): void {
    Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->user())
        ->withoutTools(['publish_article'])
        ->create();

    $tools = withinSession($session, fn (): array => app(PolicyAwareTools::class)
        ->apply([new SearchWeb, new DraftEmail, new PublishArticle]));

    expect($tools)->toHaveCount(2)
        ->and(collect($tools)->map(fn ($t) => $t->inner()::class)->all())
        ->not->toContain(PublishArticle::class);
});

it('refuses an allow list and a deny list together', function (): void {
    Clutch::fake();

    expect(fn () => Clutch::agent(ResearchAgent::class)
        ->onlyTools(['a'])
        ->withoutTools(['b'])
        ->create())
        ->toThrow(InvalidArgumentException::class, 'not both');
});

it('applies the permission mode inside an allow list, not instead of it', function (): void {
    config()->set('clutch.permissions.tools', ['publish_article' => 'irreversible']);

    Clutch::fake([ClutchResult::text('ok')]);

    $session = Clutch::agent(ResearchAgent::class)
        ->for($this->user())
        ->onlyTools(['search_web', 'publish_article'])
        ->create();

    $tools = withinSession($session, fn (): array => app(PolicyAwareTools::class)
        ->apply([new SearchWeb, new PublishArticle]));

    // Both are on the list, but the irreversible one still has to be approved.
    expect($tools)->toHaveCount(2)
        ->and($tools[1]->shouldRequestApproval(new Laravel\Ai\Tools\Request))->not->toBeNull();
});
