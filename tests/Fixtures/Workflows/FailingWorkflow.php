<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Workflows\Workflow;
use RuntimeException;

class FailingWorkflow extends Workflow
{
    public static int $before = 0;

    public function handle(array $payload): mixed
    {
        $this->step('safe', function (): string {
            static::$before++;

            return 'done';
        });

        return $this->step('explodes', function (): string {
            throw new RuntimeException('the tool refused');
        });
    }
}
