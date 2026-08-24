<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Data;

use AgentHarness\Laravel\Enums\PermissionMode;

/**
 * The harness context handed to a tool immediately before it executes.
 *
 * Tools use this to derive idempotency keys and to make authorization and
 * cancellation decisions without reaching into harness storage.
 */
final readonly class ToolInvocation
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $sessionId,
        public string $runId,
        public string $toolCallId,
        public string $toolName,
        public array $arguments = [],
        public PermissionMode $permissionMode = PermissionMode::ApproveSensitive,
        public ?string $tenantType = null,
        public int|string|null $tenantId = null,
        public ?string $participantType = null,
        public int|string|null $participantId = null,
        public int $attempt = 1,
    ) {}

    /**
     * A stable digest of the arguments, used to detect a changed retry.
     */
    public function argumentsDigest(): string
    {
        $arguments = $this->arguments;

        $normalize = function (array $value) use (&$normalize): array {
            ksort($value);

            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $normalize($item);
                }
            }

            return $value;
        };

        return hash('sha256', (string) json_encode($normalize($arguments)));
    }
}
