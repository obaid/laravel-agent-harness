<?php

declare(strict_types=1);

namespace Clutch\Laravel\Exceptions;

final class RunNotFound extends ClutchException
{
    public function errorCode(): string
    {
        return 'run_not_found';
    }

    public function statusCode(): int
    {
        return 404;
    }

    public static function withId(string $id): self
    {
        return new self("No Clutch run was found with the identifier [{$id}].");
    }
}
