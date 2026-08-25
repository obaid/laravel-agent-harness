<?php

declare(strict_types=1);

namespace Clutch\Laravel\Data;

/**
 * One resolved approval, ready to be handed back to a driver.
 */
final readonly class ApprovalDecision
{
    /**
     * @param  array<string, mixed>|null  $editedArguments  replacement arguments when the decision edited the call
     */
    public function __construct(
        public string $approvalId,
        public string $toolCallId,
        public string $toolName,
        public bool $approved,
        public ?string $reason = null,
        public ?array $editedArguments = null,
    ) {}

    public static function approve(string $approvalId, string $toolCallId, string $toolName, ?string $reason = null): self
    {
        return new self($approvalId, $toolCallId, $toolName, true, $reason);
    }

    public static function reject(string $approvalId, string $toolCallId, string $toolName, ?string $reason = null): self
    {
        return new self($approvalId, $toolCallId, $toolName, false, $reason);
    }
}
