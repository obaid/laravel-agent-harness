<?php

declare(strict_types=1);

namespace Clutch\Laravel\Events;

use Clutch\Laravel\Enums\RunStatus;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a run's lifecycle status is committed.
 *
 * Useful for metrics: run duration, queue wait, and state distribution.
 */
class RunStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $runId,
        public readonly ?RunStatus $from,
        public readonly RunStatus $to,
        public readonly string $driver,
    ) {}
}
