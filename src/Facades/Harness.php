<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Facades;

use AgentHarness\Laravel\HarnessManager;
use AgentHarness\Laravel\Testing\HarnessFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AgentHarness\Laravel\Runtime\SessionBuilder agent(class-string $agentClass)
 * @method static \AgentHarness\Laravel\Runtime\SessionBuilder runtime(string $runtime)
 * @method static \AgentHarness\Laravel\Runtime\SessionBuilder builder()
 * @method static \AgentHarness\Laravel\Models\Session session(string $sessionId)
 * @method static \AgentHarness\Laravel\Models\Session|null findSession(string $sessionId)
 * @method static \AgentHarness\Laravel\Models\Run run(string $runId)
 * @method static \AgentHarness\Laravel\Models\Run|null findRun(string $runId)
 * @method static \Illuminate\Support\Collection<int, \AgentHarness\Laravel\Models\Session> sessionsFor(object $participant, int $limit = 50)
 * @method static \Illuminate\Support\Collection<int, \AgentHarness\Laravel\Models\Approval> pendingApprovalsFor(object $participant)
 * @method static array<int, object> policy(iterable<int, object> $tools)
 * @method static \AgentHarness\Laravel\Runtime\DriverRegistry drivers()
 * @method static \AgentHarness\Laravel\HarnessManager extend(string $name, \Closure $creator)
 * @method static \AgentHarness\Laravel\Runtime\RunCoordinator coordinator()
 *
 * @see HarnessManager
 * @see HarnessFake
 */
class Harness extends Facade
{
    /**
     * Replace the harness with a deterministic fake for the rest of the test.
     *
     * @param  array<array-key, mixed>  $responses
     */
    public static function fake(array $responses = []): HarnessFake
    {
        $fake = static::getFacadeRoot()->fake($responses);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return HarnessManager::class;
    }
}
