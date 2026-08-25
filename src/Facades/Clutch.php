<?php

declare(strict_types=1);

namespace Clutch\Laravel\Facades;

use Clutch\Laravel\ClutchManager;
use Clutch\Laravel\Testing\ClutchFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Clutch\Laravel\Runtime\SessionBuilder agent(class-string $agentClass)
 * @method static \Clutch\Laravel\Runtime\SessionBuilder runtime(string $runtime)
 * @method static \Clutch\Laravel\Runtime\SessionBuilder builder()
 * @method static \Clutch\Laravel\Models\Session session(string $sessionId)
 * @method static \Clutch\Laravel\Models\Session|null findSession(string $sessionId)
 * @method static \Clutch\Laravel\Models\Run run(string $runId)
 * @method static \Clutch\Laravel\Models\Run|null findRun(string $runId)
 * @method static \Illuminate\Support\Collection<int, \Clutch\Laravel\Models\Session> sessionsFor(object $participant, int $limit = 50)
 * @method static \Illuminate\Support\Collection<int, \Clutch\Laravel\Models\Approval> pendingApprovalsFor(object $participant)
 * @method static array<int, object> policy(iterable<int, object> $tools)
 * @method static \Clutch\Laravel\Runtime\DriverRegistry drivers()
 * @method static \Clutch\Laravel\ClutchManager extend(string $name, \Closure $creator)
 * @method static \Clutch\Laravel\Runtime\RunCoordinator coordinator()
 *
 * @see ClutchManager
 * @see ClutchFake
 */
class Clutch extends Facade
{
    /**
     * Replace the harness with a deterministic fake for the rest of the test.
     *
     * @param  array<array-key, mixed>  $responses
     */
    public static function fake(array $responses = []): ClutchFake
    {
        $fake = static::getFacadeRoot()->fake($responses);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ClutchManager::class;
    }
}
