<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Exceptions;

final class DriverNotFound extends HarnessException
{
    public function errorCode(): string
    {
        return 'driver_not_found';
    }

    public function statusCode(): int
    {
        return 500;
    }

    public static function named(string $name): self
    {
        return new self("No harness driver is registered under the name [{$name}].");
    }
}
