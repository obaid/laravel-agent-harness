<?php

declare(strict_types=1);

namespace Clutch\Laravel\Runtime;

use Closure;
use Clutch\Laravel\Artifacts\ArtifactRegistrar;
use Clutch\Laravel\Data\ToolInvocation;
use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Psr\Log\LoggerInterface;

/**
 * The ambient harness context available while a run is executing.
 *
 * Tools reach this with `RunContext::current()` so they can attach artifacts,
 * check cancellation, and log with redaction without taking a harness
 * dependency in their constructor or knowing any identifiers.
 */
class RunContext
{
    protected static ?self $current = null;

    public function __construct(
        public readonly Session $session,
        public readonly Run $run,
        protected ArtifactRegistrar $artifacts,
        protected CancellationSignal $cancellation,
        protected LoggerInterface $logger,
        protected Redactor $redactor,
    ) {}

    /**
     * The context for the run executing on this worker, if any.
     */
    public static function current(): ?self
    {
        return static::$current;
    }

    /**
     * Determine whether any run is currently executing on this worker.
     */
    public static function hasCurrent(): bool
    {
        return static::$current instanceof self;
    }

    /**
     * Run a callback with this context installed, restoring the previous one after.
     *
     * @template TReturn
     *
     * @param  Closure(self): TReturn  $callback
     * @return TReturn
     */
    public function scope(Closure $callback): mixed
    {
        $previous = static::$current;

        static::$current = $this;

        try {
            return $callback($this);
        } finally {
            static::$current = $previous;
        }
    }

    /**
     * Clear the ambient context. Intended for tests.
     */
    public static function flush(): void
    {
        static::$current = null;
    }

    /**
     * Attach durable outputs to the current run.
     */
    public function artifacts(): ArtifactRegistrar
    {
        return $this->artifacts;
    }

    /**
     * The cooperative cancellation signal for this run.
     */
    public function cancellation(): CancellationSignal
    {
        return $this->cancellation;
    }

    /**
     * Determine whether cancellation has been requested.
     *
     * Long-running tools should consult this between units of work.
     */
    public function isCancelled(): bool
    {
        return $this->cancellation->isCancelled();
    }

    /**
     * The effective permission mode for this session.
     */
    public function permissionMode(): PermissionMode
    {
        return $this->session->permission_mode;
    }

    public function sessionId(): string
    {
        return $this->session->id;
    }

    public function runId(): string
    {
        return $this->run->id;
    }

    public function attempt(): int
    {
        return (int) $this->run->attempt;
    }

    /**
     * Build the invocation record for a tool call in this run.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function invocationFor(string $toolName, string $toolCallId, array $arguments = []): ToolInvocation
    {
        return new ToolInvocation(
            sessionId: $this->session->id,
            runId: $this->run->id,
            toolCallId: $toolCallId,
            toolName: $toolName,
            arguments: $arguments,
            permissionMode: $this->session->permission_mode,
            tenantType: $this->session->tenant_type,
            tenantId: $this->session->tenant_id,
            participantType: $this->session->participant_type,
            participantId: $this->session->participant_id,
            attempt: (int) $this->run->attempt,
        );
    }

    /**
     * Log with harness correlation fields, redacting sensitive context keys.
     *
     * @param  array<string, mixed>  $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, [
            ...$this->redactor->redact($context),
            'session_id' => $this->session->id,
            'run_id' => $this->run->id,
            'attempt' => $this->run->attempt,
            'driver' => $this->session->driver,
        ]);
    }
}
