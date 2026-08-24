<?php

declare(strict_types=1);

use AgentHarness\Laravel\Enums\RunStatus;
use AgentHarness\Laravel\Exceptions\DriverFailure;
use AgentHarness\Laravel\Facades\Harness;
use AgentHarness\Laravel\Models\Approval;
use AgentHarness\Laravel\Tests\Fixtures\Agents\ResearchAgent;
use AgentHarness\Laravel\Tests\Fixtures\Agents\StatelessAgent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\AgentResponse;

beforeEach(function (): void {
    // The real driver, against Laravel AI's own fake gateway.
    config()->set('agent-harness.default_driver', 'laravel-ai');

    $this->owner = $this->user();
});

it('runs a real Laravel AI agent through the harness', function (): void {
    ResearchAgent::fake(['Their weakest flank is onboarding.']);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $result = $session->prompt('Research our three closest competitors.');

    expect($result->isCompleted())->toBeTrue()
        ->and($result->text)->toBe('Their weakest flank is onboarding.')
        ->and($result->run->status)->toBe(RunStatus::Completed);
});

it('persists a Laravel AI conversation and reuses it on the next turn', function (): void {
    ResearchAgent::fake(['First answer.', 'Second answer.']);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    $session->prompt('Research our competitors.');

    $conversationId = $session->refresh()->conversation_id;

    expect($conversationId)->not->toBeNull()
        ->and(Conversation::query()->whereKey($conversationId)->exists())->toBeTrue();

    $session->prompt('Now write the memo.');

    // The second turn continued the same conversation rather than starting one.
    expect($session->refresh()->conversation_id)->toBe($conversationId)
        ->and(Conversation::query()->count())->toBe(1);
});

it('attributes the conversation to the session participant', function (): void {
    ResearchAgent::fake(['Answer.']);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $session->prompt('Research.');

    $conversation = Conversation::query()->firstOrFail();

    expect($conversation->participant_id)->toBe($this->owner->id)
        ->and($conversation->participant_type)->toBe($this->owner->getMorphClass());
});

it('translates Laravel AI stream events into harness events', function (): void {
    ResearchAgent::fake(['A short answer.']);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $run = $session->prompt('Research.')->run;

    $types = $run->events()->pluck('type')->map->value;

    expect($types)->toContain('run.created')
        ->toContain('run.started')
        ->toContain('step.started')
        ->toContain('text.delta')
        ->toContain('step.completed')
        ->toContain('usage.updated')
        ->toContain('run.completed');
});

it('captures usage reported by the provider', function (): void {
    ResearchAgent::fake([
        new Laravel\Ai\Responses\TextResponse(
            'Answer.',
            new Laravel\Ai\Responses\Data\Usage(promptTokens: 120, completionTokens: 45),
            new Laravel\Ai\Responses\Data\Meta('fake', 'fake-model'),
        ),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $usage = $session->prompt('Research.')->usage;

    expect($usage->promptTokens)->toBe(120)
        ->and($usage->completionTokens)->toBe(45)
        ->and($usage->totalTokens())->toBe(165)
        ->and($usage->steps)->toBeGreaterThan(0);
});

it('estimates cost from the configured pricing table', function (): void {
    // Price whatever provider and model this environment resolves to, so the
    // test asserts the estimator's behavior rather than a hard-coded model name.
    $provider = Laravel\Ai\Ai::textProviderFor(new ResearchAgent, config('ai.default'));

    config()->set('agent-harness.pricing', [
        $provider->name().':'.$provider->defaultTextModel() => ['input' => 3.00, 'output' => 15.00],
    ]);

    ResearchAgent::fake([
        new Laravel\Ai\Responses\TextResponse(
            'Answer.',
            new Laravel\Ai\Responses\Data\Usage(promptTokens: 1_000_000, completionTokens: 0),
            new Laravel\Ai\Responses\Data\Meta,
        ),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $result = $session->prompt('Research.');

    expect((float) $result->run->cost_usd)->toBe(3.0)
        ->and($result->usage->costUsd)->toBe(3.0);
});

it('leaves cost at zero for a model with no configured price', function (): void {
    config()->set('agent-harness.pricing', []);

    ResearchAgent::fake([
        new Laravel\Ai\Responses\TextResponse(
            'Answer.',
            new Laravel\Ai\Responses\Data\Usage(promptTokens: 1_000_000),
            new Laravel\Ai\Responses\Data\Meta,
        ),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    // An unpriced model contributes nothing rather than a guessed figure.
    expect((float) $session->prompt('Research.')->run->cost_usd)->toBe(0.0);
});

it('surfaces a Laravel AI approval pause as a durable harness approval', function (): void {
    ResearchAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call_9', 'publish_article', ['article_id' => 7], 'Publishing is irreversible.'),
        ]),
    ]);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $result = $session->prompt('Publish it.');

    expect($result->isAwaitingApproval())->toBeTrue();

    $approval = Approval::query()->firstOrFail();

    expect($approval->tool_call_id)->toBe('call_9')
        ->and($approval->tool_name)->toBe('publish_article')
        ->and($approval->arguments)->toBe(['article_id' => 7])
        ->and($approval->reason)->toBe('Publishing is irreversible.');
});

it('refuses an agent that cannot persist its conversation', function (): void {
    expect(fn () => Harness::agent(StatelessAgent::class)->for($this->owner)->create())
        ->toThrow(DriverFailure::class, 'RemembersConversations');
});

it('names the exact fix when an agent cannot persist its conversation', function (): void {
    try {
        Harness::agent(StatelessAgent::class)->for($this->owner)->create();
    } catch (DriverFailure $e) {
        expect($e->getMessage())
            ->toContain('use Laravel\Ai\Concerns\RemembersConversations;')
            ->toContain("->configure('stateless', true)");

        return;
    }

    $this->fail('Expected a DriverFailure describing the missing trait.');
});

it('allows a deliberately single-turn agent to opt out', function (): void {
    StatelessAgent::fake(['One-shot answer.']);

    $session = Harness::agent(StatelessAgent::class)
        ->for($this->owner)
        ->configure('stateless', true)
        ->create();

    $result = $session->prompt('Answer once.');

    expect($result->isCompleted())->toBeTrue()
        ->and($result->text)->toBe('One-shot answer.');
});

it('declares its continuation guarantees truthfully', function (): void {
    $driver = Harness::drivers()->driver('laravel-ai');

    $capabilities = $driver->capabilities();

    expect($capabilities->streaming)->toBeTrue()
        ->and($capabilities->approvals)->toBeTrue()
        ->and($capabilities->sessionResume)->toBeTrue()
        ->and($capabilities->structuredOutput)->toBeTrue()
        // A provider request that dies mid-flight is repeated, not resumed.
        ->and($capabilities->inFlightContinuation)->toBeFalse()
        ->and($capabilities->sandboxRequired)->toBeFalse();
});

it('keeps only the conversation reference in its checkpoint', function (): void {
    ResearchAgent::fake(['Answer.']);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();
    $session->prompt('Research.');

    $checkpoint = $session->checkpoints()->latest('created_at')->firstOrFail();

    expect($checkpoint->payload)->toHaveKey('conversation_id')
        ->and($checkpoint->driver)->toBe('laravel-ai')
        ->and($checkpoint->hasIntactPayload())->toBeTrue()
        ->and($checkpoint->portable)->toBeTrue();
});

it('explains how to install the Laravel AI migrations when they are missing', function (): void {
    ResearchAgent::fake(['Answer.']);

    $session = Harness::agent(ResearchAgent::class)->for($this->owner)->create();

    // Stand in for an application that installed the harness but never
    // published Laravel AI's own migrations. Raised through the store rather
    // than by dropping the tables, so the surrounding test transaction stays
    // usable and the assertion is about the message, not the schema.
    $this->app->bind(ConversationStore::class, fn (): ConversationStore => new class implements ConversationStore
    {
        public function latestConversationId(string $participantType, string|int $participantId): ?string
        {
            return null;
        }

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
        {
            throw new QueryException('testing', 'insert into "agent_conversations" ...', [], new PDOException(
                'SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "agent_conversations" does not exist'
            ));
        }

        public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, $prompt): string
        {
            return '';
        }

        public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, $prompt, $response): ?string
        {
            return null;
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return new Collection;
        }

        public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
        {
            //
        }
    });

    $result = $session->prompt('Research.');

    expect($result->isFailed())->toBeTrue()
        ->and($result->run->failure_message)
        ->toContain('Laravel AI conversation tables are missing')
        ->toContain('vendor:publish');
});
