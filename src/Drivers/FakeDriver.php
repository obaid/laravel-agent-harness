<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Drivers;

use AgentHarness\Laravel\Contracts\DriverEventSink;
use AgentHarness\Laravel\Contracts\HarnessDriver;
use AgentHarness\Laravel\Data\Continuation;
use AgentHarness\Laravel\Data\DriverCheckpoint;
use AgentHarness\Laravel\Data\DriverSession;
use AgentHarness\Laravel\Data\StartSession;
use AgentHarness\Laravel\Data\TurnInput;
use AgentHarness\Laravel\Data\TurnResult;
use AgentHarness\Laravel\Enums\EventType;
use AgentHarness\Laravel\Enums\FailureCategory;
use AgentHarness\Laravel\Runtime\CancellationSignal;
use AgentHarness\Laravel\Runtime\RunContext;
use AgentHarness\Laravel\Testing\ScriptedResponse;
use AgentHarness\Laravel\ValueObjects\BudgetUsage;
use AgentHarness\Laravel\ValueObjects\DriverCapabilities;
use AgentHarness\Laravel\ValueObjects\NormalizedFailure;
use Closure;
use Illuminate\Support\Str;

/**
 * A deterministic driver that replays scripted responses.
 *
 * Used by `Harness::fake()` and by the driver contract suite, so the full
 * session and run lifecycle can be exercised without a model provider.
 */
class FakeDriver implements HarnessDriver
{
    public const SCHEMA_VERSION = 1;

    /** @var array<array-key, mixed> */
    protected array $responses;

    protected int $index = 0;

    /**
     * Bumped whenever the script is replaced.
     *
     * A checkpoint carries the position within a script; adopting that position
     * only makes sense while the script is still the same one. Re-scripting
     * mid-session means "here are the next responses", so the position resets.
     */
    protected int $generation = 0;

    /** @var array<int, array{prompt: string, run_id: string}> */
    public array $prompts = [];

    /**
     * @param  array<array-key, mixed>  $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            streaming: true,
            hostTools: true,
            nativeTools: false,
            approvals: true,
            structuredOutput: true,
            sessionResume: true,
            inFlightContinuation: false,
            manualCompaction: false,
        );
    }

    /**
     * Replace the scripted responses.
     *
     * @param  array<array-key, mixed>  $responses
     */
    public function script(array $responses): static
    {
        $this->responses = $responses;
        $this->index = 0;
        $this->generation++;

        return $this;
    }

    public function start(StartSession $command): DriverSession
    {
        return new DriverSession(
            sessionId: $command->sessionId,
            driver: $this->name(),
            state: ['turns' => 0],
            conversationId: 'fake-conversation-'.$command->sessionId,
        );
    }

    public function runTurn(
        DriverSession $session,
        TurnInput $input,
        DriverEventSink $events,
        CancellationSignal $cancellation,
    ): TurnResult {
        $this->prompts[] = ['prompt' => $input->prompt, 'run_id' => $input->runId];

        $response = $this->nextResponse($input->prompt);

        return $this->play($session, $response, $events, $cancellation, $input->prompt);
    }

    public function continueTurn(
        DriverSession $session,
        Continuation $continuation,
        DriverEventSink $events,
        CancellationSignal $cancellation,
    ): TurnResult {
        $events->emit(EventType::StepStarted, ['step' => 1, 'reason' => 'approval_continuation']);

        // A rejected call still produces a result the agent can react to; the
        // fake mirrors that so tests exercise the same path as the real driver.
        foreach ($continuation->decisions as $decision) {
            $events->emit($decision->approved ? EventType::ToolCallCompleted : EventType::ToolCallFailed, [
                'tool_call_id' => $decision->toolCallId,
                'tool' => $decision->toolName,
                'result' => $decision->approved ? 'ok' : null,
                'error' => $decision->approved ? null : ($decision->reason ?? 'The tool call was rejected.'),
                'denied' => ! $decision->approved,
            ]);
        }

        $response = $this->nextResponse('');

        return $this->play($session, $response, $events, $cancellation, '');
    }

    public function checkpoint(DriverSession $session): DriverCheckpoint
    {
        return new DriverCheckpoint(
            driver: $this->name(),
            schemaVersion: self::SCHEMA_VERSION,
            payload: [
                'session_id' => $session->sessionId,
                'conversation_id' => $session->conversationId,
                'state' => $session->state,
                'response_index' => $this->index,
                'script_generation' => $this->generation,
            ],
            portable: true,
            sessionId: $session->sessionId,
        );
    }

    public function restore(DriverCheckpoint $checkpoint): DriverSession
    {
        // Only resume mid-script if the script has not been replaced since.
        if ((int) $checkpoint->payload('script_generation', 0) === $this->generation) {
            $this->index = (int) $checkpoint->payload('response_index', 0);
        }

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
        //
    }

    /**
     * Emit the events a scripted response implies and return its outcome.
     */
    protected function play(
        DriverSession $session,
        ScriptedResponse $response,
        DriverEventSink $events,
        CancellationSignal $cancellation,
        string $prompt,
    ): TurnResult {
        $session = $session->withState(['turns' => (int) $session->state('turns', 0) + 1]);

        if ($cancellation->isCancelled()) {
            return TurnResult::cancelled(usage: new BudgetUsage, session: $session);
        }

        $events->emit(EventType::StepStarted, ['step' => 1]);

        foreach ($response->toolCalls as $call) {
            if ($cancellation->isCancelled()) {
                return TurnResult::cancelled(usage: $response->usage, session: $session);
            }

            $toolCallId = 'call_'.Str::lower((string) Str::ulid());

            $events->emit(EventType::ToolCallRequested, [
                'tool_call_id' => $toolCallId,
                'tool' => $call['tool'],
                'arguments' => $call['arguments'],
            ]);

            $events->emit(EventType::ToolCallCompleted, [
                'tool_call_id' => $toolCallId,
                'tool' => $call['tool'],
                'result' => $call['result'],
            ]);
        }

        if ($response->kind === ScriptedResponse::APPROVAL) {
            $events->checkpoint($this->checkpoint($session)->because('approval_pause'));

            return TurnResult::awaitingApproval(
                pendingApprovals: $response->pendingApprovals,
                usage: $response->usage,
                session: $session,
            );
        }

        if ($response->kind === ScriptedResponse::FAILURE) {
            return TurnResult::failed(
                new NormalizedFailure(FailureCategory::DriverError, (string) $response->failureMessage),
                usage: $response->usage,
                session: $session,
            );
        }

        foreach (explode(' ', (string) $response->text) as $index => $word) {
            if ($cancellation->isCancelled()) {
                return TurnResult::cancelled(usage: $response->usage, session: $session);
            }

            $events->emit(EventType::TextDelta, [
                'message_id' => 'msg_'.$session->sessionId,
                'delta' => $index > 0 ? ' '.$word : $word,
            ]);
        }

        foreach ($response->artifacts as $artifact) {
            RunContext::current()?->artifacts()->add($artifact);
        }

        $events->emit(EventType::StepCompleted, [
            'step' => 1,
            'usage' => $response->usage->toArray(),
        ]);

        return TurnResult::completed(
            text: $response->text,
            structuredOutput: $response->structured,
            usage: $response->usage,
            session: $session,
        );
    }

    /**
     * Resolve the response for a prompt, by exact key, substring, or order.
     */
    protected function nextResponse(string $prompt): ScriptedResponse
    {
        $response = $this->matchByKey($prompt) ?? $this->matchByIndex();

        return $this->marshal($response, $prompt);
    }

    protected function matchByKey(string $prompt): mixed
    {
        if ($prompt === '') {
            return null;
        }

        foreach ($this->responses as $key => $response) {
            if (! is_string($key)) {
                continue;
            }

            if ($key === $prompt || Str::is($key, $prompt) || str_contains($prompt, $key)) {
                return $response;
            }
        }

        return null;
    }

    protected function matchByIndex(): mixed
    {
        $list = array_values(array_filter(
            $this->responses,
            fn (mixed $value, mixed $key): bool => is_int($key),
            ARRAY_FILTER_USE_BOTH,
        ));

        return $list[$this->index++] ?? null;
    }

    protected function marshal(mixed $response, string $prompt): ScriptedResponse
    {
        return match (true) {
            $response instanceof ScriptedResponse => $response,
            is_string($response) => new ScriptedResponse(ScriptedResponse::TEXT, text: $response),
            is_array($response) => new ScriptedResponse(
                ScriptedResponse::STRUCTURED,
                text: (string) json_encode($response),
                structured: $response,
            ),
            $response instanceof Closure => $this->marshal($response($prompt), $prompt),
            default => new ScriptedResponse(
                ScriptedResponse::TEXT,
                text: 'Fake harness response for prompt: '.Str::words($prompt, 10),
            ),
        };
    }
}
