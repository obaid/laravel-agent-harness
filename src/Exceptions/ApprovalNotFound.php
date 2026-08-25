<?php

declare(strict_types=1);

namespace Clutch\Laravel\Exceptions;

final class ApprovalNotFound extends ClutchException
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
        return new self("No Clutch approval was found with the identifier [{$id}].");
    }
}
