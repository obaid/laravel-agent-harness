<?php

declare(strict_types=1);

namespace Clutch\Laravel\Enums;

enum FailureCategory: string
{
    case ValidationError = 'validation_error';
    case AuthorizationError = 'authorization_error';
    case ProviderError = 'provider_error';
    case RateLimited = 'rate_limited';
    case ToolError = 'tool_error';
    case DriverError = 'driver_error';
    case CheckpointError = 'checkpoint_error';
    case SandboxError = 'sandbox_error';
    case BudgetExceeded = 'budget_exceeded';
    case Cancelled = 'cancelled';
    case WorkerLost = 'worker_lost';
    case Unknown = 'unknown';

    /**
     * Determine whether a failure of this category may be retried automatically.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::ProviderError, self::WorkerLost => true,
            default => false,
        };
    }
}
