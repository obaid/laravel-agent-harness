<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use Exception;

/**
 * Control flow, not failure: the workflow reached a slice boundary.
 *
 * Thrown by the runtime between steps when the turn's slice limits are
 * spent. Everything completed so far is already persisted, so the driver
 * translates this into a suspended turn: the run parks, the queue job ends
 * inside its worker's timeout, and a continuation re-enters `handle()` from
 * the top, replaying the finished steps and picking up at the first one
 * still missing. A workflow whose wall-clock exceeds any single worker's
 * timeout completes as a chain of sub-timeout jobs instead of being killed
 * mid-flight.
 */
final class WorkflowSliced extends Exception
{
    public function __construct(
        /** The last step that completed before the boundary. */
        public readonly ?string $lastStep,
        /** Steps executed in this slice. */
        public readonly int $executed,
        /** Wall-clock seconds this slice worked. */
        public readonly float $elapsedSeconds,
    ) {
        parent::__construct(sprintf(
            'Workflow sliced after %d step%s (%.1fs).',
            $executed,
            $executed === 1 ? '' : 's',
            $elapsedSeconds,
        ));
    }
}
