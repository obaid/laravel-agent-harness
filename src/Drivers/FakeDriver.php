<?php

declare(strict_types=1);

namespace Clutch\Laravel\Drivers;

use Closure;
use Clutch\Laravel\Contracts\ClutchDriver;
use Clutch\Laravel\Contracts\DriverEventSink;
use Clutch\Laravel\Data\Continuation;
use Clutch\Laravel\Data\DriverCheckpoint;
use Clutch\Laravel\Data\DriverSession;
use Clutch\Laravel\Data\StartSession;
use Clutch\Laravel\Data\TurnInput;
use Clutch\Laravel\Data\TurnResult;
use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Enums\FailureCategory;
use Clutch\Laravel\Runtime\CancellationSignal;
use Clutch\Laravel\Runtime\RunContext;
use Clutch\Laravel\Testing\ScriptedResponse;
use Clutch\Laravel\ValueObjects\BudgetUsage;
use Clutch\Laravel\ValueObjects\DriverCapabilities;
use Clutch\Laravel\ValueObjects\NormalizedFailure;
use Clutch\Laravel\ValueObjects\TurnLimits;
use Illuminate\Support\Str;

/**
 * A deterministic driver that replays scripted responses.
 *
 * Used by `Clutch::fake()` and by the driver contract suite, so the full
 * session and run lifecycle can be exercised without a model provider.
 */
class FakeDriver implements ClutchDriver
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
            manualCompaction: true,
            timeSlicing: true,
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

        return $this->play($session, $response, $events, $cancellation, $input->prompt, $input->limits);
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

        // A continuation with no decisions resumes a suspended turn rather
        // than answering an approval, so the scripted response is not advanced.
        if ($continuation->decisions === []) {
            return $this->play(
                $session,
                $this->currentResponse(),
                $events,
                $cancellation,
                '',
                $continuation->limits,
            );
        }

        $response = $this->nextResponse('');

        return $this->play($session, $response, $events, $cancellation, '', $continuation->limits);
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
        TurnLimits $limits = new TurnLimits,
    ): TurnResult {
        $session = $session->withState(['turns' => (int) $session->state('turns', 0) + 1]);

        // Where the previous slice of this turn stopped.
        $sliceStart = (int) $session->state('slice_cursor', 0);
        $startedAt = microtime(true);
        $stepsThisSlice = 0;

        if ($cancellation->isCancelled()) {
            return TurnResult::cancelled(usage: new BudgetUsage, session: $session);
        }

        $events->emit(EventType::StepStarted, ['step' => $sliceStart + 1]);

        foreach ($response->toolCalls as $index => $call) {
            if ($cancellation->isCancelled()) {
                return TurnResult::cancelled(usage: $response->usage, session: $session);
            }

            // Replay past what earlier slices already did.
            if ($index < $sliceStart) {
                continue;
            }

            if ($limits->reached($stepsThisSlice, microtime(true) - $startedAt)) {
                return $this->suspend($session, $index, $events, $response, $limits, $stepsThisSlice, $startedAt);
            }

            $stepsThisSlice++;

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

        // The text phase is one more step, so a one-step-per-slice caller gets
        // a boundary between the last tool and the answer.
        if ($limits->reached($stepsThisSlice, microtime(true) - $startedAt)) {
            return $this->suspend(
                $session, count($response->toolCalls), $events, $response, $limits, $stepsThisSlice, $startedAt,
            );
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
            session: $session->withState(['slice_cursor' => 0]),
        );
    }

    /**
     * Park the turn at a slice boundary, recording where to pick it up.
     */
    protected function suspend(
        DriverSession $session,
        int $cursor,
        DriverEventSink $events,
        ScriptedResponse $response,
        TurnLimits $limits,
        int $steps,
        float $startedAt,
    ): TurnResult {
        $session = $session->withState(['slice_cursor' => $cursor]);

        $events->checkpoint($this->checkpoint($session)->because('slice_boundary'));

        return TurnResult::suspended(
            session: $session,
            usage: $response->usage,
            reason: $limits->reasonFor($steps, microtime(true) - $startedAt),
        );
    }

    /**
     * The response the current turn is working through, without advancing.
     */
    protected function currentResponse(): ScriptedResponse
    {
        $list = array_values(array_filter(
            $this->responses,
            fn (mixed $value, mixed $key): bool => is_int($key),
            ARRAY_FILTER_USE_BOTH,
        ));

        return $this->marshal($list[max(0, $this->index - 1)] ?? null, '');
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
