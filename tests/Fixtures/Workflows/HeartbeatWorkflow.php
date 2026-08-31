<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Workflows\Workflow;

/**
 * A workflow whose step pretends to have taken an hour.
 *
 * Backdating the heartbeat from inside the step body is the only way to prove
 * the runtime beats it *around* the work rather than once at the start: if
 * nothing beat afterwards, the row would still say an hour ago.
 */
class HeartbeatWorkflow extends Workflow
{
    public function handle(array $payload): mixed
    {
        return $this->steps([
            'slow' => static function (): string {
                Run::query()->update(['heartbeat_at' => now()->subHour()]);

                return 'done';
            },
        ]);
    }
}
