<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Workflows\Workflow;

class ParallelWorkflow extends Workflow
{
    public function handle(array $payload): mixed
    {
        return $this->steps([
            'account' => fn (): string => 'account:'.($payload['id'] ?? '?'),
            'usage' => fn (): int => 42,
        ]);
    }
}
