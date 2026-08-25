<?php

declare(strict_types=1);

namespace Clutch\Laravel\Exceptions;

final class DriverNotFound extends ClutchException
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
        return new self("No driver is registered under the name [{$name}].");
    }
}
