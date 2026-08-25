<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use RuntimeException;
use Throwable;

/**
 * A workflow body raised, and the step it raised in is named.
 *
 * The original exception is kept as the previous throwable so the stack trace
 * that matters is not lost behind the harness.
 */
final class WorkflowFailed extends RuntimeException
{
    public function __construct(
        public readonly string $workflow,
        public readonly ?string $step,
        Throwable $previous,
    ) {
        parent::__construct(
            $step === null
                ? sprintf('[%s] failed: %s', $workflow, $previous->getMessage())
                : sprintf('[%s] failed in step [%s]: %s', $workflow, $step, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
