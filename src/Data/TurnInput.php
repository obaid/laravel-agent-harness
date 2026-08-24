<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Data;

use AgentHarness\Laravel\Enums\PermissionMode;
use AgentHarness\Laravel\ValueObjects\RunBudget;

/**
 * One new unit of input for a driver to process.
 */
final readonly class TurnInput
{
    /**
     * @param  array<int, mixed>  $attachments
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $runId,
        public string $prompt,
        public array $attachments = [],
        public PermissionMode $permissionMode = PermissionMode::ApproveSensitive,
        public ?RunBudget $budget = null,
        public bool $streaming = false,
        public array $options = [],
    ) {}

    /**
     * Get a driver option.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }
}
