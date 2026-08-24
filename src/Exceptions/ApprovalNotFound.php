<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Exceptions;

final class ApprovalNotFound extends HarnessException
{
    public function errorCode(): string
    {
        return 'approval_not_found';
    }

    public function statusCode(): int
    {
        return 404;
    }

    public static function withId(string $id): self
    {
        return new self("No harness approval was found with the identifier [{$id}].");
    }
}
