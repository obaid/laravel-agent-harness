<?php

declare(strict_types=1);

namespace Clutch\Laravel\Exceptions;

final class ArtifactUnavailable extends ClutchException
{
    public function errorCode(): string
    {
        return 'artifact_unavailable';
    }

    public function statusCode(): int
    {
        return 404;
    }

    public static function missing(string $id): self
    {
        return new self("The artifact [{$id}] is no longer available in storage.");
    }
}
