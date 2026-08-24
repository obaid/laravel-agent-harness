<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Exceptions;

final class DriverFailure extends HarnessException
{
    public function errorCode(): string
    {
        return 'driver_failure';
    }

    public function statusCode(): int
    {
        return 500;
    }

    public static function from(string $driver, string $message): self
    {
        return new self("The [{$driver}] harness driver failed: {$message}");
    }
}
