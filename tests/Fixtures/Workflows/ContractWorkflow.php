<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Workflows\Workflow;

/**
 * The simplest workflow that still does something, for the driver contract.
 */
class ContractWorkflow extends Workflow
{
    public function handle(array $payload): mixed
    {
        return $this->step('echo', fn (): array => ['said' => $payload['prompt'] ?? 'nothing']);
    }
}
