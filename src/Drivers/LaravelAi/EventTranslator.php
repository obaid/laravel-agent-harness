<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Drivers\LaravelAi;

use AgentHarness\Laravel\Enums\EventType;
use Laravel\Ai\Streaming\Events as Ai;
use Laravel\Ai\Streaming\Events\StreamEvent;

/**
 * Maps Laravel AI stream events onto the harness's provider-neutral set.
 *
 * Provider-specific detail is kept under a namespaced `provider` key so a
 * consumer written against the harness protocol never has to know which model
 * produced an event, while nothing is thrown away.
 */
class EventTranslator
{
    /**
     * Translate one Laravel AI event.
     *
     * Returns null for events the harness does not model; the caller records
     * those as `driver.event` after redaction.
     *
     * @return array{type: EventType, payload: array<string, mixed>}|null
     */
    public function translate(StreamEvent $event): ?array
    {
        return match (true) {
            $event instanceof Ai\StreamStart => [
                'type' => EventType::StepStarted,
                'payload' => [
                    'provider' => $event->provider,
                    'model' => $event->model,
                    'message_id' => $event->id,
                ],
            ],

            $event instanceof Ai\TextDelta => [
                'type' => EventType::TextDelta,
                'payload' => [
                    'message_id' => $event->messageId,
                    'delta' => $event->delta,
                ],
            ],

            $event instanceof Ai\ReasoningDelta => [
                'type' => EventType::ReasoningDelta,
                'payload' => [
                    'reasoning_id' => $event->reasoningId,
                    'delta' => $event->delta,
                ],
            ],

            $event instanceof Ai\ToolCall => [
                'type' => EventType::ToolCallRequested,
                'payload' => [
                    'tool_call_id' => $event->toolCall->id,
                    'tool' => $event->toolCall->name,
                    'arguments' => $event->toolCall->arguments,
                ],
            ],

            $event instanceof Ai\ToolResult => [
                'type' => $event->successful ? EventType::ToolCallCompleted : EventType::ToolCallFailed,
                'payload' => [
                    'tool_call_id' => $event->toolResult->id,
                    'tool' => $event->toolResult->name,
                    'result' => $event->successful ? $event->toolResult->result : null,
                    'error' => $event->error,
                    'denied' => $event->denied,
                ],
            ],

            $event instanceof Ai\ToolApprovalRequest => [
                'type' => EventType::ApprovalRequested,
                'payload' => [
                    // The harness records durable approval rows separately; this
                    // event exists so a live stream shows the pause immediately.
                    'approvals' => $event->pendingApprovals
                        ->map(fn ($approval): array => [
                            'tool_call_id' => $approval->id,
                            'tool' => $approval->tool,
                            'arguments' => $approval->arguments,
                            'reason' => $approval->reason,
                        ])
                        ->values()
                        ->all(),
                ],
            ],

            $event instanceof Ai\StreamEnd => [
                'type' => EventType::StepCompleted,
                'payload' => [
                    'reason' => $event->reason,
                    'usage' => $event->usage->toArray(),
                ],
            ],

            $event instanceof Ai\Error => [
                'type' => EventType::ToolCallFailed,
                'payload' => [
                    'error' => $event->message,
                    'recoverable' => $event->recoverable,
                ],
            ],

            default => null,
        };
    }

    /**
     * The name a non-modeled event is recorded under.
     */
    public function rawTypeFor(StreamEvent $event): string
    {
        return $event->type();
    }
}
