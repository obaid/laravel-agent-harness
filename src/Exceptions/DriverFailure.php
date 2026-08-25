<?php

declare(strict_types=1);

namespace Clutch\Laravel\Exceptions;

final class DriverFailure extends ClutchException
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
        return new self("The [{$driver}] driver failed: {$message}");
    }
}
