<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Exceptions;

final class ArtifactUnavailable extends HarnessException
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
