<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use RuntimeException;

/**
 * Thrown by `pause()` to unwind out of a workflow body.
 *
 * This is control flow rather than an error. The driver catches it, parks the
 * run awaiting a decision, and lets the worker exit. Nothing about a paused
 * workflow is a failure, so this never reaches the application.
 */
final class WorkflowPaused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $name,
        public readonly array $data = [],
        public readonly ?string $why = null,
    ) {
        parent::__construct(sprintf('The workflow paused at [%s].', $name));
    }
}
