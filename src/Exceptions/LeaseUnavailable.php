<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Exceptions;

final class LeaseUnavailable extends HarnessException
{
    public function errorCode(): string
    {
        return 'lease_unavailable';
    }

    public function statusCode(): int
    {
        return 409;
    }

    public static function forSession(string $sessionId): self
    {
        return new self("Another worker currently holds the execution lease for session [{$sessionId}].");
    }
}
