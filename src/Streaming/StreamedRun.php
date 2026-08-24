<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Streaming;

use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Models\RunEvent;
use AgentHarness\Laravel\Runtime\HarnessResult;
use Closure;
use Fiber;
use Illuminate\Contracts\Support\Responsable;
use IteratorAggregate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Traversable;

/**
 * A synchronous run whose events are consumed as they are recorded.
 *
 * The run executes inside a fiber so each persisted event can be handed to the
 * consumer the moment it is written, rather than buffering the whole turn and
 * replaying it at the end.
 *
 * @implements IteratorAggregate<int, RunEvent>
 */
class StreamedRun implements IteratorAggregate, Responsable
{
    protected bool $usesVercelProtocol = false;

    protected ?Run $completedRun = null;

    /** @var array<int, Closure(RunEvent): void> */
    protected array $callbacks = [];

    /**
     * @param  Closure(Closure(RunEvent): void): Run  $executor
     */
    public function __construct(
        protected Run $run,
        protected Closure $executor,
    ) {}

    /**
     * Emit the stream using Vercel's AI SDK data protocol.
     *
     * @see https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol
     */
    public function usingVercelDataProtocol(bool $value = true): static
    {
        $this->usesVercelProtocol = $value;

        return $this;
    }

    /**
     * Observe every event without consuming the iterator yourself.
     *
     * @param  Closure(RunEvent): void  $callback
     */
    public function each(Closure $callback): static
    {
        $this->callbacks[] = $callback;

        return $this;
    }

    /**
     * Run to completion and return the terminal result.
     */
    public function wait(): HarnessResult
    {
        foreach ($this as $event) {
            // Drain the stream; callbacks registered with each() still fire.
            unset($event);
        }

        return HarnessResult::fromRun($this->completedRun ?? $this->run->refresh());
    }

    /**
     * The run this stream belongs to.
     */
    public function run(): Run
    {
        return $this->completedRun ?? $this->run;
    }

    public function getIterator(): Traversable
    {
        $fiber = new Fiber(function (): Run {
            return ($this->executor)(function (RunEvent $event): void {
                Fiber::suspend($event);
            });
        });

        $event = $fiber->start();

        while (! $fiber->isTerminated()) {
            if ($event instanceof RunEvent) {
                foreach ($this->callbacks as $callback) {
                    $callback($event);
                }

                yield $event;
            }

            $event = $fiber->resume();
        }

        $this->completedRun = $fiber->getReturn();
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     */
    public function toResponse($request): Response
    {
        $protocol = $this->usesVercelProtocol
            ? new VercelDataProtocol
            : null;

        return new StreamedResponse(function () use ($protocol): void {
            foreach ($this as $event) {
                foreach ($this->linesFor($event, $protocol) as $line) {
                    echo $line;
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }

            echo $protocol instanceof VercelDataProtocol
                ? "data: [DONE]\n\n"
                : "data: [DONE]\n\n";

            flush();
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
    protected function linesFor(RunEvent $event, ?VercelDataProtocol $protocol): array
    {
        if (! $protocol instanceof VercelDataProtocol) {
            return ['data: '.json_encode($event->toEnvelope())."\n\n"];
        }

        return array_map(
            fn (array $frame): string => 'data: '.json_encode($frame)."\n\n",
            $protocol->map($event),
        );
    }
}
