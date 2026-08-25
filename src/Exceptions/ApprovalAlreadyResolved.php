<?php

declare(strict_types=1);

namespace Clutch\Laravel\Exceptions;

final class ApprovalAlreadyResolved extends ClutchException
{
    public function errorCode(): string
    {
        return 'approval_already_resolved';
    }

    public function statusCode(): int
    {
        return 409;
    }

    public static function with(string $id, string $status): self
    {
        return new self(
            "Approval [{$id}] was already resolved as [{$status}] and may not be reversed."
        );
    }
}
