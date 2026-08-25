<?php

declare(strict_types=1);

use Clutch\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'Contracts');

/**
 * Run a callback inside a real Clutch run context for the given session.
 *
 * Tool policy is evaluated against the ambient context, so exercising it means
 * standing one up rather than calling the engine directly.
 */
function withinSession(Clutch\Laravel\Models\Session $session, Closure $callback): mixed
{
    $run = Clutch\Laravel\Facades\Clutch::coordinator()->createRun($session, 'Policy preflight.');

    $context = new Clutch\Laravel\Runtime\RunContext(
        session: $session,
        run: $run,
        artifacts: new Clutch\Laravel\Artifacts\ArtifactRegistrar(
            $run,
            app(Clutch\Laravel\Artifacts\ArtifactManager::class),
        ),
        cancellation: Clutch\Laravel\Runtime\CancellationSignal::never(),
        logger: app('log'),
        redactor: app(Clutch\Laravel\Runtime\Redactor::class),
    );

    return $context->scope($callback);
}
