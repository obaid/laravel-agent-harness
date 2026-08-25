<?php

declare(strict_types=1);

namespace Clutch\Laravel\Contracts;

use Clutch\Laravel\Data\ToolInvocation;

/**
 * A tool whose side effect must not be repeated when a run is retried.
 *
 * The harness records the key and result before allowing a retry to advance;
 * a repeated invocation returns the stored result instead of executing again.
 */
interface IdempotentTool
{
    /**
     * Build the key that identifies this side effect uniquely.
     *
     * The key must be stable across retries of the same logical action and
     * distinct between different actions.
     */
    public function idempotencyKey(ToolInvocation $invocation): string;
}
