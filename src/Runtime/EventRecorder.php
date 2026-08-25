<?php

declare(strict_types=1);

namespace Clutch\Laravel\Runtime;

use Closure;
use Clutch\Laravel\Checkpoints\CheckpointStore;
use Clutch\Laravel\Contracts\DriverEventSink;
use Clutch\Laravel\Data\DriverCheckpoint;
use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\RunEvent;
use Clutch\Laravel\Models\Session;
use Illuminate\Support\Collection;

/**
 * The sink handed to a driver for the duration of one turn.
 *
 * Every event is persisted before it is broadcast, so a client reconnecting
 * with a cursor always sees exactly what a live subscriber saw — direct and
 * queued execution therefore produce equivalent stored histories.
 */
class EventRecorder implements DriverEventSink
{
    /** @var array<int, Closure(RunEvent): void> */
    protected array $listeners = [];

    /** @var Collection<int, RunEvent> */
    protected Collection $recorded;

    public function __construct(
        protected Session $session,
        protected Run $run,
        protected EventStore $events,
        protected CheckpointStore $checkpoints,
        protected Redactor $redactor,
    ) {
        $this->recorded = new Collection;
    }

    /**
     * Record one normalized event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(EventType $type, array $payload = []): void
    {
        // Tool payloads get the application's serializer applied first so
        // unapproved fields never reach storage at all.
        if (isset($payload['tool']) && is_string($payload['tool'])) {
            $payload = $this->redactor->redactToolPayload($payload['tool'], $payload);
        }

        $event = $this->events->append($this->run, $type, $payload);

        if ($event instanceof RunEvent) {
            $this->recorded->push($event);

            foreach ($this->listeners as $listener) {
                $listener($event);
            }
        }
    }

    /**
     * Record an un-normalized runtime event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function emitRaw(string $driverType, array $payload = []): void
    {
        $this->emit(EventType::DriverEvent, [
            'driver' => $this->session->driver,
            'driver_type' => $driverType,
            'data' => $payload,
        ]);
    }

    /**
     * Persist a checkpoint at a safe boundary mid-turn.
     */
    public function checkpoint(DriverCheckpoint $checkpoint): void
    {
        $stored = $this->checkpoints->store(
            $this->session,
            $this->run,
            $checkpoint->for($this->session->id, $this->run->id),
        );

        $this->run->forceFill(['last_checkpoint_id' => $stored->id])->saveQuietly();

        $this->emit(EventType::CheckpointCreated, [
            'checkpoint_id' => $stored->id,
            'reason' => $stored->reason,
            'portable' => (bool) $stored->portable,
            'event_sequence' => (int) $stored->event_sequence,
        ]);
    }

    /**
     * Observe every event as it is recorded.
     *
     * Direct HTTP streaming uses this so it forwards exactly the persisted
     * history rather than a parallel view of it.
     *
     * @param  Closure(RunEvent): void  $listener
     */
    public function listen(Closure $listener): static
    {
        $this->listeners[] = $listener;

        return $this;
    }

    /**
     * Everything recorded through this sink.
     *
     * @return Collection<int, RunEvent>
     */
    public function recorded(): Collection
    {
        return $this->recorded;
    }

    /**
     * Point the recorder at a reloaded run model.
     */
    public function forRun(Run $run): static
    {
        $this->run = $run;

        return $this;
    }
}
