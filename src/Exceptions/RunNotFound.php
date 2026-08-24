<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Exceptions;

final class RunNotFound extends HarnessException
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
        return new self("No harness run was found with the identifier [{$id}].");
    }
}
