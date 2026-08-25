<?php

declare(strict_types=1);

namespace Clutch\Laravel\Http\Controllers;

use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\EventStore;
use Clutch\Laravel\Streaming\EventStreamResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a run's events over server-sent events, replaying from a cursor.
 *
 * A client that disconnects after event 42 reconnects with `?after=42` and
 * receives a gap-free, ordered continuation.
 */
class RunEventStreamController
{
    public function __construct(
        protected EventStreamResponse $stream,
        protected EventStore $events,
    ) {}

    public function __invoke(Request $request, string $run): Response
    {
        $model = Run::query()->with('session')->findOrFail($run);

        $this->authorizeRun($request, $model);

        return $this->stream->for(
            $model,
            after: max(0, (int) $request->query('after', 0)),
            vercelProtocol: $request->boolean('vercel'),
        );
    }

    /**
     * Every lookup is participant-scoped; a run belonging to another
     * participant is not merely hidden from the list, it is unreachable here.
     */
    protected function authorizeRun(Request $request, Run $model): void
    {
        $model->authorizeFor($request->user());
    }
}
