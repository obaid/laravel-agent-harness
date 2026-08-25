<?php

declare(strict_types=1);

namespace Clutch\Laravel\Data;

use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\ValueObjects\RunBudget;
use Clutch\Laravel\ValueObjects\TurnLimits;

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
        /** How much work this slice may do before handing the turn back. */
        public TurnLimits $limits = new TurnLimits,
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
