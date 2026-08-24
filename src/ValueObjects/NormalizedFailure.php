<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\ValueObjects;

use AgentHarness\Laravel\Enums\FailureCategory;
use Illuminate\Contracts\Support\Arrayable;
use Throwable;

/**
 * A user-safe description of why a run failed.
 *
 * Provider response bodies, credentials, and stack traces are deliberately
 * excluded; they belong in protected application logs.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class NormalizedFailure implements Arrayable
{
    public function __construct(
        public FailureCategory $category,
        public string $message,
        public ?string $exceptionClass = null,
        public bool $retryable = false,
    ) {}

    /**
     * Normalize a throwable into a safe failure description.
     */
    public static function fromThrowable(Throwable $e, ?FailureCategory $category = null): self
    {
        $category ??= self::classify($e);

        return new self(
            category: $category,
            message: self::safeMessage($e, $category),
            exceptionClass: $e::class,
            retryable: $category->isRetryable(),
        );
    }

    /**
     * Infer a failure category from an exception.
     */
    public static function classify(Throwable $e): FailureCategory
    {
        return match (true) {
            $e instanceof \AgentHarness\Laravel\Exceptions\BudgetExceeded => FailureCategory::BudgetExceeded,
            $e instanceof \AgentHarness\Laravel\Exceptions\CheckpointIncompatible => FailureCategory::CheckpointError,
            $e instanceof \AgentHarness\Laravel\Exceptions\RunNotAuthorized => FailureCategory::AuthorizationError,
            $e instanceof \AgentHarness\Laravel\Exceptions\DriverFailure => FailureCategory::DriverError,
            $e instanceof \Illuminate\Auth\Access\AuthorizationException => FailureCategory::AuthorizationError,
            $e instanceof \Illuminate\Validation\ValidationException => FailureCategory::ValidationError,
            $e instanceof \InvalidArgumentException => FailureCategory::ValidationError,
            self::looksRateLimited($e) => FailureCategory::RateLimited,
            self::looksLikeProviderError($e) => FailureCategory::ProviderError,
            default => FailureCategory::Unknown,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'message' => $this->message,
            'exception_class' => $this->exceptionClass,
            'retryable' => $this->retryable,
        ];
    }

    private static function looksRateLimited(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests')
            || str_contains($message, '429');
    }

    private static function looksLikeProviderError(Throwable $e): bool
    {
        return str_starts_with($e::class, 'Laravel\\Ai\\Exceptions\\')
            || $e instanceof \Illuminate\Http\Client\RequestException
            || $e instanceof \Illuminate\Http\Client\ConnectionException;
    }

    /**
     * Produce a message that is safe to show to an end user.
     */
    private static function safeMessage(Throwable $e, FailureCategory $category): string
    {
        // Harness exceptions are written to be user-safe by contract.
        if ($e instanceof \AgentHarness\Laravel\Exceptions\HarnessException) {
            return $e->getMessage();
        }

        return match ($category) {
            FailureCategory::RateLimited => 'The model provider rate limited this run. It may be retried shortly.',
            FailureCategory::ProviderError => 'The model provider returned an error while processing this run.',
            FailureCategory::ToolError => 'A tool failed while processing this run.',
            FailureCategory::AuthorizationError => 'This run was not authorized to continue.',
            FailureCategory::ValidationError => 'The run input or configuration was invalid.',
            FailureCategory::WorkerLost => 'The worker processing this run stopped unexpectedly.',
            FailureCategory::CheckpointError => 'The stored checkpoint for this run could not be restored.',
            FailureCategory::SandboxError => 'The sandbox backing this run became unavailable.',
            default => 'The run failed because of an unexpected error.',
        };
    }
}
