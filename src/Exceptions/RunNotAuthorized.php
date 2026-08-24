<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Exceptions;

final class RunNotAuthorized extends HarnessException
{
    public function errorCode(): string
    {
        return 'run_not_authorized';
    }

    public function statusCode(): int
    {
        return 403;
    }

    public static function forRun(string $runId): self
    {
        return new self("You are not authorized to access run [{$runId}].");
    }

    public static function forSession(string $sessionId): self
    {
        return new self("You are not authorized to access session [{$sessionId}].");
    }
}
