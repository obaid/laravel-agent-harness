<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests\Fixtures\Workflows;

use Clutch\Laravel\Tests\Fixtures\Agents\PublishingAgent;
use Clutch\Laravel\Workflows\Workflow;

/**
 * An agent that stops for its own approval, from inside a workflow step.
 *
 * The counters are what matter: the body must not continue past a prompt the
 * agent never finished, and the step around it must not be recorded.
 */
class NestedApprovalWorkflow extends Workflow
{
    protected static ?string $agent = PublishingAgent::class;

    public static int $reachedEnd = 0;

    public static int $stepBodyRan = 0;

    public static function reset(): void
    {
        static::$reachedEnd = 0;
        static::$stepBodyRan = 0;
    }

    public function handle(array $payload): mixed
    {
        $said = $this->step('publish', function () {
            static::$stepBodyRan++;

            return $this->prompt('Publish the article.')->text;
        });

        static::$reachedEnd++;

        return ['said' => $said];
    }
}
