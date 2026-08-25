<?php

declare(strict_types=1);

namespace Clutch\Laravel\Streaming;

use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\EventStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-sent events for a run, replaying from a cursor then following live.
 *
 * The replay-to-live handoff re-reads storage after subscribing so an event
 * written during the subscription window cannot slip through the gap; the
 * client deduplicates by `(run_id, sequence)` because network delivery is at
 * least once.
 */
class EventStreamResponse
{
    public function __construct(
        protected EventStore $events,
        protected int $pollIntervalMicroseconds = 250_000,
        protected int $keepAliveSeconds = 15,
        protected int $maxDurationSeconds = 300,
    ) {}

    /**
     * Build the streamed response for a run.
     */
    public function for(Run $run, int $after = 0, bool $vercelProtocol = false): Response
    {
        $protocol = $vercelProtocol ? new VercelDataProtocol : null;

        return new StreamedResponse(function () use ($run, $after, $protocol): void {
            $cursor = $after;
            $startedAt = time();
            $lastKeepAlive = time();

            while (true) {
                $batch = $this->events->after($run->id, $cursor);

                foreach ($batch as $event) {
                    $cursor = $event->sequence;

                    foreach ($this->framesFor($event, $protocol) as $frame) {
                        echo $frame;
                    }

                    if ($event->isTerminal()) {
                        echo "data: [DONE]\n\n";
                        $this->flush();

                        return;
                    }
                }

                $this->flush();

                if (connection_aborted() === 1) {
                    return;
                }

                if ((time() - $startedAt) >= $this->maxDurationSeconds) {
                    // Let the client reconnect with its cursor rather than
                    // holding a worker open indefinitely.
                    echo "event: timeout\ndata: {\"reconnect\":true,\"after\":{$cursor}}\n\n";
                    $this->flush();

                    return;
                }

                if ((time() - $lastKeepAlive) >= $this->keepAliveSeconds) {
                    echo ": keep-alive\n\n";
                    $this->flush();
                    $lastKeepAlive = time();
                }

                if ($batch->isEmpty()) {
                    usleep($this->pollIntervalMicroseconds);
                }
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function framesFor(\Clutch\Laravel\Models\RunEvent $event, ?VercelDataProtocol $protocol): array
    {
        if (! $protocol instanceof VercelDataProtocol) {
            return ["id: {$event->sequence}\nevent: {$event->type->value}\n"
                .'data: '.json_encode($event->toEnvelope())."\n\n"];
        }

        return array_map(
            fn (array $frame): string => 'data: '.json_encode($frame)."\n\n",
            $protocol->map($event),
        );
    }

    protected function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }
}
