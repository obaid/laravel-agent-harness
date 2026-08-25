<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use RuntimeException;

/**
 * Thrown at a step boundary once cancellation has been requested.
 *
 * Like a pause this is control flow, not an error. Steps that already
 * finished keep their results, so a cancelled workflow that is later retried
 * does not repeat the work it had already done.
 */
final class WorkflowCancelled extends RuntimeException
{
    public function __construct(public readonly ?string $why = null)
    {
        parent::__construct($why ?? 'The workflow was cancelled.');
    }
}
