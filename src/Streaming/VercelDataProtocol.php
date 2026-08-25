<?php

declare(strict_types=1);

namespace Clutch\Laravel\Streaming;

use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Models\RunEvent;

/**
 * Maps harness events onto Vercel's AI SDK data stream protocol.
 *
 * One harness event can produce zero, one, or several protocol frames, so the
 * mapper always returns a list.
 *
 * @see https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
 */
class VercelDataProtocol
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function map(RunEvent $event): array
    {
        $payload = $event->payload ?? [];

        return match ($event->type) {
            EventType::RunStarted => [
                ['type' => 'start', 'messageId' => $event->run_id],
                ['type' => 'start-step'],
            ],

            EventType::TextDelta => [[
                'type' => 'text-delta',
                'id' => $payload['message_id'] ?? $event->run_id,
                'delta' => $payload['delta'] ?? '',
            ]],

            EventType::ReasoningDelta => [[
                'type' => 'reasoning-delta',
                'id' => $payload['reasoning_id'] ?? $event->run_id,
                'delta' => $payload['delta'] ?? '',
            ]],

            EventType::ToolCallRequested => [[
                'type' => 'tool-input-available',
                'toolCallId' => $payload['tool_call_id'] ?? '',
                'toolName' => $payload['tool'] ?? '',
                'input' => $payload['arguments'] ?? [],
            ]],

            EventType::ToolCallCompleted => [[
                'type' => 'tool-output-available',
                'toolCallId' => $payload['tool_call_id'] ?? '',
                'output' => $payload['result'] ?? null,
            ]],

            EventType::ToolCallFailed => [[
                'type' => 'tool-output-error',
                'toolCallId' => $payload['tool_call_id'] ?? '',
                'errorText' => $payload['error'] ?? 'The tool call failed.',
            ]],

            // The Vercel protocol has no approval frame, so the pause surfaces
            // as data the client can render into its own approval UI.
            EventType::ApprovalRequested => [[
                'type' => 'data-approval-request',
                'id' => $payload['approval_id'] ?? '',
                'data' => [
                    'approval_id' => $payload['approval_id'] ?? null,
                    'tool_call_id' => $payload['tool_call_id'] ?? null,
                    'tool' => $payload['tool'] ?? null,
                    'arguments' => $payload['arguments'] ?? [],
                    'reason' => $payload['reason'] ?? null,
                ],
            ]],

            EventType::ApprovalResolved => [[
                'type' => 'data-approval-resolved',
                'id' => $payload['approval_id'] ?? '',
                'data' => [
                    'approval_id' => $payload['approval_id'] ?? null,
                    'status' => $payload['status'] ?? null,
                ],
            ]],

            EventType::ArtifactCreated => [[
                'type' => 'data-artifact',
                'id' => $payload['artifact_id'] ?? '',
                'data' => $payload,
            ]],

            EventType::StepCompleted => [['type' => 'finish-step'], ['type' => 'start-step']],

            EventType::RunCompleted => [['type' => 'finish-step'], ['type' => 'finish']],

            EventType::RunAwaitingApproval => [['type' => 'finish-step'], ['type' => 'finish']],

            EventType::RunFailed => [[
                'type' => 'error',
                'errorText' => $payload['message'] ?? 'The run failed.',
            ]],

            EventType::RunCancelled => [[
                'type' => 'error',
                'errorText' => $payload['reason'] ?? 'The run was cancelled.',
            ]],

            EventType::RunBudgetExceeded => [[
                'type' => 'error',
                'errorText' => 'The run stopped because its ['.($payload['limit'] ?? 'budget').'] limit was reached.',
            ]],

            default => [],
        };
    }
}
