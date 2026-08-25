<?php

declare(strict_types=1);

namespace Clutch\Laravel\Data;

/**
 * A tool call a driver has paused on, awaiting a human decision.
 */
final readonly class PendingApproval
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments = [],
        public ?string $reason = null,
    ) {}
}
