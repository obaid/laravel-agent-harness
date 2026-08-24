<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Exceptions;

final class CheckpointIncompatible extends HarnessException
{
    public function errorCode(): string
    {
        return 'checkpoint_incompatible';
    }

    public function statusCode(): int
    {
        return 409;
    }

    public static function forSchema(string $driver, int $found, int $expected): self
    {
        return new self(
            "A [{$driver}] checkpoint using schema version [{$found}] cannot be restored ".
            "by a driver expecting version [{$expected}]."
        );
    }
}
