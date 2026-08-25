<?php

declare(strict_types=1);

namespace Clutch\Laravel\Exceptions;

final class ToolTimedOut extends ClutchException
{
    public function errorCode(): string
    {
        return 'tool_timed_out';
    }

    public function statusCode(): int
    {
        return 504;
    }

    public static function after(string $tool, int $seconds, ?int $elapsed = null): self
    {
        $suffix = $elapsed === null ? '' : " It ran for {$elapsed} seconds.";

        return new self("The tool [{$tool}] exceeded its {$seconds} second deadline.{$suffix}");
    }
}
