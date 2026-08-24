<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Drivers\LaravelAi;

use AgentHarness\Laravel\Budgets\BudgetManager;
use AgentHarness\Laravel\Budgets\CostEstimator;
use AgentHarness\Laravel\Contracts\DriverEventSink;
use AgentHarness\Laravel\Contracts\HarnessDriver;
use AgentHarness\Laravel\Data\Continuation;
use AgentHarness\Laravel\Data\DriverCheckpoint;
use AgentHarness\Laravel\Data\DriverSession;
use AgentHarness\Laravel\Data\PendingApproval;
use AgentHarness\Laravel\Data\StartSession;
use AgentHarness\Laravel\Data\TurnInput;
use AgentHarness\Laravel\Data\TurnResult;
use AgentHarness\Laravel\Enums\EventType;
use AgentHarness\Laravel\Exceptions\DriverFailure;
use AgentHarness\Laravel\Runtime\CancellationSignal;
use AgentHarness\Laravel\ValueObjects\BudgetUsage;
use AgentHarness\Laravel\ValueObjects\DriverCapabilities;
use AgentHarness\Laravel\ValueObjects\RunBudget;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use RuntimeException;
use Throwable;

/**
 * The default driver: an ordinary Laravel AI agent, run inside the application.
 *
 * Continuity comes from Laravel AI's own conversation store. The driver keeps
 * the conversation identifier in its checkpoint so a later worker — in another
 * process, after a deploy — restores exactly the context the previous turn left.
 *
 * The driver checkpoints at safe model and tool boundaries. It does not promise
 * byte-perfect continuation from the middle of a provider HTTP request: if a
 * worker dies mid-request the current step may be repeated, which is why
 * side-effecting tools need an idempotency contract.
 */
class LaravelAiDriver implements HarnessDriver
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Container $container,
        protected BudgetManager $budgets,
        protected CostEstimator $costs,
        protected EventTranslator $translator,
        protected array $config = [],
    ) {}

    public function name(): string
    {
        return 'laravel-ai';
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            streaming: true,
            hostTools: true,
            nativeTools: true,
            approvals: true,
            structuredOutput: true,
            sessionResume: true,
            // A provider request that dies mid-flight is repeated, not resumed.
            inFlightContinuation: false,
            manualCompaction: false,
            sandboxRequired: false,
            workspaceRequired: false,
        );
    }

    public function start(StartSession $command): DriverSession
    {
        $agentClass = $command->agentClass
            ?? throw new DriverFailure('The laravel-ai driver requires an agent class.');

        $agent = $this->resolveAgent($agentClass);

        $this->assertRemembersConversations($agent, $command);

        return new DriverSession(
            sessionId: $command->sessionId,
            driver: $this->name(),
            state: [
                'agent_class' => $agentClass,
                'remembers_conversations' => $this->remembersConversations($agent),
                'structured' => $agent instanceof HasStructuredOutput,
                // Provider, model and timeout overrides ride along in the
                // checkpoint so every later turn resolves them identically.
                'provider' => $command->config('provider'),
                'model' => $command->config('model'),
                'timeout' => $command->config('timeout'),
            ],
            conversationId: null,
        );
    }

    public function runTurn(
        DriverSession $session,
        TurnInput $input,
        DriverEventSink $events,
        CancellationSignal $cancellation,
    ): TurnResult {
        // Nothing new may start once cancellation has been observed.
        if ($cancellation->isCancelled()) {
            return TurnResult::cancelled(session: $session);
        }

        $agent = $this->prepareAgent($session, $input->options['participant'] ?? null);

        return $agent instanceof HasStructuredOutput
            ? $this->runStructured($session, $agent, $input->prompt, $input->attachments, $events, $cancellation, $input->budget)
            : $this->runStreamed($session, $agent, $input->prompt, $input->attachments, $events, $cancellation, $input->budget);
    }

    public function continueTurn(
        DriverSession $session,
        Continuation $continuation,
        DriverEventSink $events,
        CancellationSignal $cancellation,
    ): TurnResult {
        if ($cancellation->isCancelled()) {
            return TurnResult::cancelled(session: $session);
        }

        if ($session->conversationId === null) {
            throw new DriverFailure(
                'A paused Laravel AI turn cannot be resumed without its conversation. '.
                'The approval state lives on the conversation, so the agent must use the '.
                'Laravel\\Ai\\Concerns\\RemembersConversations trait.'
            );
        }

        $agent = $this->prepareAgent($session, $continuation->options['participant'] ?? null);

        $decisions = $this->toDecisions($continuation);

        $budget = $continuation->options['budget'] ?? null;

        return $agent instanceof HasStructuredOutput
            ? $this->runStructured($session, $agent, $decisions, [], $events, $cancellation, $budget)
            : $this->runStreamed($session, $agent, $decisions, [], $events, $cancellation, $budget);
    }

    public function checkpoint(DriverSession $session): DriverCheckpoint
    {
        return new DriverCheckpoint(
            driver: $this->name(),
            schemaVersion: self::SCHEMA_VERSION,
            payload: [
                'session_id' => $session->sessionId,
                // The conversation ID is the whole of the resumable state; the
                // messages themselves live in Laravel AI's conversation store.
                'conversation_id' => $session->conversationId,
                'state' => $session->state,
            ],
            portable: true,
            sessionId: $session->sessionId,
        );
    }

    public function restore(DriverCheckpoint $checkpoint): DriverSession
    {
        return new DriverSession(
            sessionId: (string) $checkpoint->payload('session_id'),
            driver: $this->name(),
            state: (array) $checkpoint->payload('state', []),
            conversationId: $checkpoint->payload('conversation_id'),
        );
    }

    public function stop(DriverSession $session): DriverCheckpoint
    {
        return $this->checkpoint($session)->because('session_stopped');
    }

    public function destroy(DriverSession $session): void
    {
        // Host-resident agents hold no runtime resources of their own. The
        // conversation is retained deliberately: destroying a harness session
        // must not silently delete an application's conversation history.
    }

    // Execution ----------------------------------------------------------

    /**
     * Run through Laravel AI's stream, translating events as they arrive.
     *
     * Streaming is used even for a synchronous prompt so that queued and direct
     * execution record the same history, and so budgets can be enforced at real
     * step boundaries rather than only after the fact.
     *
     * @param  array<int, mixed>  $attachments
     */
    protected function runStreamed(
        DriverSession $session,
        Agent $agent,
        Decisions|string $prompt,
        array $attachments,
        DriverEventSink $events,
        CancellationSignal $cancellation,
        ?RunBudget $budget,
    ): TurnResult {
        $usage = new BudgetUsage;
        $startedAt = microtime(true);
        $exhausted = null;
        $provider = null;
        $model = null;

        try {
            $response = $agent->stream(
                $prompt,
                $attachments,
                $this->configuredProvider($session),
                $this->configuredModel($session),
                $this->configuredTimeout($session),
            );

            foreach ($response as $event) {
                [$usage, $provider, $model] = $this->recordEvent($event, $events, $usage, $provider, $model);

                $usage = $usage
                    ->withElapsedSeconds((int) round(microtime(true) - $startedAt))
                    ->withCostUsd($this->costs->estimate($usage, $provider, $model));

                if ($cancellation->isCancelled()) {
                    // Abandoning the generator stops the provider loop before
                    // it begins another step.
                    return TurnResult::cancelled(usage: $usage, session: $session);
                }

                if ($budget instanceof RunBudget
                    && ($exhausted = $this->budgets->exhaustedLimit($budget, $usage)) !== null) {
                    break;
                }
            }
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }

        $session = $this->adoptConversation($session, $response);

        if ($exhausted !== null) {
            return TurnResult::budgetExceeded(
                ['limit' => $exhausted],
                text: $response->text,
                usage: $usage,
                session: $session,
            );
        }

        $streamed = $this->streamedResponse($response);

        if ($streamed?->hasPendingApprovals()) {
            $events->checkpoint($this->checkpoint($session)->because('approval_pause'));

            return TurnResult::awaitingApproval(
                pendingApprovals: $this->toPendingApprovals($streamed),
                text: $response->text,
                usage: $usage,
                session: $session,
            );
        }

        return TurnResult::completed(
            text: $response->text,
            usage: $usage,
            session: $session,
        );
    }

    /**
     * Run a structured agent.
     *
     * Laravel AI only returns validated structured output from the buffered
     * prompt path, so this route is used for structured agents. Events are
     * synthesized from the completed response rather than streamed.
     *
     * @param  array<int, mixed>  $attachments
     */
    protected function runStructured(
        DriverSession $session,
        Agent $agent,
        Decisions|string $prompt,
        array $attachments,
        DriverEventSink $events,
        CancellationSignal $cancellation,
        ?RunBudget $budget,
    ): TurnResult {
        $startedAt = microtime(true);

        try {
            $response = $agent->prompt(
                $prompt,
                $attachments,
                $this->configuredProvider($session),
                $this->configuredModel($session),
                $this->configuredTimeout($session),
            );
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }

        $usage = $this->usageFrom($response->usage)
            ->withSteps(max(1, $response->steps->count()))
            ->withToolCalls($response->toolCalls->count())
            ->withElapsedSeconds((int) round(microtime(true) - $startedAt));

        $usage = $usage->withCostUsd($this->costs->estimate($usage, $response->meta->provider, $response->meta->model));

        $this->replayResponseEvents($response, $events);

        $session = $this->adoptConversation($session, $response);

        if ($response->hasPendingApprovals()) {
            $events->checkpoint($this->checkpoint($session)->because('approval_pause'));

            return TurnResult::awaitingApproval(
                pendingApprovals: $this->toPendingApprovals($response),
                text: $response->text,
                usage: $usage,
                session: $session,
            );
        }

        if ($budget instanceof RunBudget
            && ($exhausted = $this->budgets->exhaustedLimit($budget, $usage)) !== null) {
            return TurnResult::budgetExceeded(['limit' => $exhausted], $response->text, $usage, $session);
        }

        return TurnResult::completed(
            text: $response->text,
            structuredOutput: property_exists($response, 'structured') ? $response->structured : null,
            usage: $usage,
            session: $session,
        );
    }

    // Translation --------------------------------------------------------

    /**
     * Record one Laravel AI event and fold its usage in.
     *
     * @return array{BudgetUsage, ?string, ?string}
     */
    protected function recordEvent(
        StreamEvent $event,
        DriverEventSink $events,
        BudgetUsage $usage,
        ?string $provider,
        ?string $model,
    ): array {
        $translated = $this->translator->translate($event);

        if ($translated === null) {
            $events->emitRaw($this->translator->rawTypeFor($event), $event->toArray());

            return [$usage, $provider, $model];
        }

        $events->emit($translated['type'], $translated['payload']);

        return [
            $this->foldUsage($usage, $translated),
            $translated['payload']['provider'] ?? $provider,
            $translated['payload']['model'] ?? $model,
        ];
    }

    /**
     * Accumulate step, tool, and token counts from a translated event.
     *
     * @param  array{type: EventType, payload: array<string, mixed>}  $translated
     */
    protected function foldUsage(BudgetUsage $usage, array $translated): BudgetUsage
    {
        return match ($translated['type']) {
            EventType::StepStarted => $usage->withSteps($usage->steps + 1),
            EventType::ToolCallRequested => $usage->withToolCalls($usage->toolCalls + 1),
            EventType::StepCompleted => $usage->add(
                BudgetUsage::fromArray($translated['payload']['usage'] ?? [])
            ),
            default => $usage,
        };
    }

    /**
     * Synthesize the event history for a buffered response.
     */
    protected function replayResponseEvents(AgentResponse $response, DriverEventSink $events): void
    {
        foreach ($response->steps as $index => $step) {
            $events->emit(EventType::StepStarted, ['step' => $index + 1]);
            $events->emit(EventType::StepCompleted, [
                'step' => $index + 1,
                'usage' => $step->usage->toArray(),
            ]);
        }

        foreach ($response->toolCalls as $call) {
            $events->emit(EventType::ToolCallRequested, [
                'tool_call_id' => $call->id,
                'tool' => $call->name,
                'arguments' => $call->arguments,
            ]);
        }

        foreach ($response->toolResults as $result) {
            $events->emit(EventType::ToolCallCompleted, [
                'tool_call_id' => $result->id,
                'tool' => $result->name,
                'result' => $result->result,
            ]);
        }

        if (filled($response->text)) {
            $events->emit(EventType::TextDelta, [
                'message_id' => $response->invocationId,
                'delta' => $response->text,
            ]);
        }
    }

    /**
     * @return array<int, PendingApproval>
     */
    protected function toPendingApprovals(AgentResponse $response): array
    {
        return $response->pendingApprovals
            ->map(fn ($approval): PendingApproval => new PendingApproval(
                toolCallId: $approval->id,
                toolName: $approval->tool,
                arguments: $approval->arguments,
                reason: $approval->reason,
            ))
            ->values()
            ->all();
    }

    /**
     * Convert harness decisions into Laravel AI's decision map.
     */
    protected function toDecisions(Continuation $continuation): Decisions
    {
        $decisions = [];

        foreach ($continuation->decisions as $decision) {
            $decisions[$decision->toolCallId] = match (true) {
                $decision->editedArguments !== null => Decision::edit($decision->editedArguments),
                $decision->approved => Decision::approve(),
                default => Decision::reject($decision->reason),
            };
        }

        if ($decisions === []) {
            throw new DriverFailure('A continuation was requested with no resolved approval decisions.');
        }

        // Anything the harness has no record of is refused rather than assumed.
        return Decisions::from($decisions)->rejectRemaining(
            'No decision was recorded for this tool call.'
        );
    }

    protected function usageFrom(Usage $usage): BudgetUsage
    {
        return new BudgetUsage(
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            reasoningTokens: $usage->reasoningTokens,
            cacheReadInputTokens: $usage->cacheReadInputTokens,
            cacheWriteInputTokens: $usage->cacheWriteInputTokens,
        );
    }

    // Agent wiring -------------------------------------------------------

    /**
     * Resolve the agent and restore its conversation context.
     */
    protected function prepareAgent(DriverSession $session, ?object $participant = null): Agent
    {
        $agent = $this->resolveAgent((string) $session->state('agent_class'));

        if (! $this->remembersConversations($agent)) {
            return $agent;
        }

        /** @var Agent&\Laravel\Ai\Contracts\RemembersConversations $agent */
        if ($session->conversationId !== null) {
            $agent->continue($session->conversationId, $participant);

            return $agent;
        }

        if ($participant instanceof Model) {
            $agent->forUser($participant);

            return $agent;
        }

        // Laravel AI only starts remembering once a turn has a participant or
        // an existing conversation. A harness session without a participant
        // still needs continuity, so the conversation is opened up front and
        // the agent is pointed at it.
        $agent->continue(
            $this->container->make(ConversationStore::class)->storeConversation(
                null,
                null,
                'Harness session '.$session->sessionId,
            )
        );

        return $agent;
    }

    protected function resolveAgent(string $agentClass): Agent
    {
        $agent = $this->container->make($agentClass);

        if (! $agent instanceof Agent) {
            throw new DriverFailure("The class [{$agentClass}] is not a Laravel AI agent.");
        }

        return $agent;
    }

    protected function remembersConversations(object $agent): bool
    {
        return in_array(RemembersConversations::class, class_uses_recursive($agent), true);
    }

    /**
     * Refuse to create a session whose agent cannot carry context forward.
     *
     * Laravel AI only persists a conversation for agents using the
     * `RemembersConversations` trait, and approval state lives on that
     * conversation. Without it, a second turn would silently lose context and a
     * pause could never be resumed in another process — exactly the guarantees
     * this package exists to make, so it fails here instead.
     */
    protected function assertRemembersConversations(Agent $agent, StartSession $command): void
    {
        if ($this->remembersConversations($agent) || $command->config('stateless') === true) {
            return;
        }

        $class = $agent::class;

        throw new DriverFailure(
            "The agent [{$class}] does not use the Laravel\\Ai\\Concerns\\RemembersConversations trait, ".
            'so Laravel AI will not persist its conversation. Durable sessions and approval resumption '.
            "both depend on that conversation.\n\n".
            "Add the trait to the agent:\n\n".
            "    use Laravel\\Ai\\Concerns\\RemembersConversations;\n".
            "    use Laravel\\Ai\\Contracts\\RemembersConversations as RemembersConversationsContract;\n\n".
            "    class {$this->shortName($class)} implements Agent, RemembersConversationsContract\n".
            "    {\n".
            "        use Promptable, RemembersConversations;\n".
            "    }\n\n".
            "For a genuinely single-turn agent, opt out explicitly with ->configure('stateless', true)."
        );
    }

    protected function shortName(string $class): string
    {
        return class_basename($class);
    }

    /**
     * Carry the conversation the turn produced back onto the driver session.
     */
    protected function adoptConversation(DriverSession $session, AgentResponse|StreamableAgentResponse $response): DriverSession
    {
        return $response->conversationId !== null
            ? $session->withConversationId($response->conversationId)
            : $session;
    }

    protected function streamedResponse(StreamableAgentResponse $response): ?AgentResponse
    {
        $streamed = null;

        $response->then(function (AgentResponse $completed) use (&$streamed): void {
            $streamed = $completed;
        });

        return $streamed;
    }

    /**
     * @return array<string, string|null>|string|null
     */
    protected function configuredProvider(DriverSession $session): array|string|null
    {
        return $session->state('provider') ?? ($this->config['provider'] ?? null);
    }

    protected function configuredModel(DriverSession $session): ?string
    {
        return $session->state('model') ?? ($this->config['model'] ?? null);
    }

    protected function configuredTimeout(DriverSession $session): ?int
    {
        $timeout = $session->state('timeout') ?? ($this->config['timeout'] ?? null);

        return $timeout === null ? null : (int) $timeout;
    }

    protected function wrap(Throwable $e): Throwable
    {
        if ($e instanceof DriverFailure) {
            return $e;
        }

        if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'No AI providers were configured')) {
            return new DriverFailure(
                'No AI provider is configured. Set one up as described in the Laravel AI documentation, '.
                'or call Harness::fake() in tests.'
            );
        }

        // Laravel AI's conversation tables are the harness's continuity
        // mechanism, and their migrations ship with laravel/ai rather than with
        // this package. A missing table here is a setup step, not a bug.
        if ($e instanceof QueryException && $this->mentionsConversationTable($e)) {
            return new DriverFailure(
                'The Laravel AI conversation tables are missing, so this session has nowhere to store its '.
                "context. Publish and run Laravel AI's migrations:\n\n".
                '    php artisan vendor:publish --provider="Laravel\\Ai\\AiServiceProvider"'."\n".
                '    php artisan migrate'
            );
        }

        return $e;
    }

    protected function mentionsConversationTable(QueryException $e): bool
    {
        $message = $e->getMessage();

        foreach ([
            (string) config('ai.conversations.tables.conversations', 'agent_conversations'),
            (string) config('ai.conversations.tables.messages', 'agent_conversation_messages'),
        ] as $table) {
            if (str_contains($message, $table)) {
                return true;
            }
        }

        return false;
    }
}
