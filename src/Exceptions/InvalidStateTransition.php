<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Exceptions;

final class InvalidStateTransition extends HarnessException
{
    public function errorCode(): string
    {
        return 'invalid_state_transition';
    }

    public function statusCode(): int
    {
        return 409;
    }

    public static function forRun(string $runId, string $from, string $to): self
    {
        return new self("Run [{$runId}] cannot transition from [{$from}] to [{$to}].");
    }

    public static function forSession(string $sessionId, string $from, string $to): self
    {
        return new self("Session [{$sessionId}] cannot transition from [{$from}] to [{$to}].");
    }
}
