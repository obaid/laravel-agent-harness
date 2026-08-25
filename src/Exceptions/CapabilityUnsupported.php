<?php

declare(strict_types=1);

namespace Clutch\Laravel\Exceptions;

final class CapabilityUnsupported extends ClutchException
{
    public function errorCode(): string
    {
        return 'capability_unsupported';
    }

    public function statusCode(): int
    {
        return 400;
    }

    public static function for(string $driver, string $capability): self
    {
        return new self(
            "The [{$driver}] driver does not support [{$capability}]. ".
            'The harness will not silently degrade a requested safety or durability feature.'
        );
    }
}
