<?php

declare(strict_types=1);

namespace Clutch\Laravel\Exceptions;

final class SessionBusy extends ClutchException
{
    public function errorCode(): string
    {
        return 'session_busy';
    }

    public function statusCode(): int
    {
        return 409;
    }

    public static function withActiveRun(string $sessionId, string $runId): self
    {
        return new self(
            "Session [{$sessionId}] already has an active run [{$runId}]. ".
            'A session may only process one run at a time.'
        );
    }
}
