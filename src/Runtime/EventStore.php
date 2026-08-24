<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Runtime;

use AgentHarness\Laravel\Enums\EventType;
use AgentHarness\Laravel\Events\HarnessEventRecorded;
use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Models\RunEvent;
use AgentHarness\Laravel\Support\Id;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/**
 * The append-only, ordered record of what happened during a run.
 *
 * Sequence numbers are assigned while holding the run row lock, so they never
 * decrease or repeat within a run even under concurrent appends. Broadcasting
 * is scheduled after commit so a subscriber can never observe an event the
 * database has not yet committed.
 */
class EventStore
{
    public function __construct(
        protected Connection $connection,
        protected Redactor $redactor,
        protected bool $persistDeltas = true,
    ) {}

    /**
     * Append one event to a run and return the persisted record.
     *
     * Callers already inside a transaction get their event committed with
     * whatever state change accompanies it — that pairing is the invariant
     * that keeps a terminal event from ever becoming visible without its
     * terminal state.
     *
     * @param  array<string, mixed>  $payload
     */
    public function append(Run $run, EventType $type, array $payload = []): ?RunEvent
    {
        if ($type->isDelta() && ! $this->persistDeltas) {
            return null;
        }

        $payload = $this->redactor->redact($payload);

        $event = $this->connection->transaction(function () use ($run, $type, $payload): RunEvent {
            $sequence = $this->nextSequence($run);

            $event = new RunEvent([
                'id' => Id::event(),
                'session_id' => $run->session_id,
                'run_id' => $run->id,
                'sequence' => $sequence,
                'type' => $type,
                'payload' => $payload,
                'occurred_at' => Carbon::now(),
            ]);

            $event->save();

            $run->setAttribute('last_event_sequence', $sequence);

            return $event;
        });

        $this->broadcastAfterCommit($event);

        return $event;
    }

    /**
     * Append several events in one transaction, preserving their given order.
     *
     * @param  array<int, array{type: EventType, payload?: array<string, mixed>}>  $events
     * @return Collection<int, RunEvent>
     */
    public function appendMany(Run $run, array $events): Collection
    {
        $events = array_values(array_filter(
            $events,
            fn (array $event): bool => $this->persistDeltas || ! $event['type']->isDelta(),
        ));

        if ($events === []) {
            return new Collection;
        }

        $records = $this->connection->transaction(function () use ($run, $events): Collection {
            $sequence = $this->nextSequence($run, count($events));
            $records = new Collection;
            $now = Carbon::now();

            foreach ($events as $index => $event) {
                $record = new RunEvent([
                    'id' => Id::event(),
                    'session_id' => $run->session_id,
                    'run_id' => $run->id,
                    'sequence' => $sequence - (count($events) - 1) + $index,
                    'type' => $event['type'],
                    'payload' => $this->redactor->redact($event['payload'] ?? []),
                    'occurred_at' => $now,
                ]);

                $record->save();

                $records->push($record);
            }

            $run->setAttribute('last_event_sequence', $sequence);

            return $records;
        });

        foreach ($records as $record) {
            $this->broadcastAfterCommit($record);
        }

        return $records;
    }

    /**
     * Read stored events after a cursor.
     *
     * @return Collection<int, RunEvent>
     */
    public function after(string $runId, int $sequence = 0, int $limit = 500): Collection
    {
        return RunEvent::query()
            ->where('run_id', $runId)
            ->where('sequence', '>', $sequence)
            ->orderBy('sequence')
            ->limit($limit)
            ->get();
    }

    /**
     * The highest sequence recorded for a run.
     */
    public function latestSequence(string $runId): int
    {
        return (int) RunEvent::query()->where('run_id', $runId)->max('sequence');
    }

    /**
     * Determine whether a run's stream has been closed by a terminal event.
     */
    public function hasTerminalEvent(string $runId): bool
    {
        return RunEvent::query()
            ->where('run_id', $runId)
            ->whereIn('type', [
                EventType::RunCompleted->value,
                EventType::RunFailed->value,
                EventType::RunCancelled->value,
                EventType::RunBudgetExceeded->value,
            ])
            ->exists();
    }

    /**
     * Reserve the next sequence number(s) while holding the run row lock.
     *
     * Returns the highest reserved value.
     */
    protected function nextSequence(Run $run, int $count = 1): int
    {
        $current = (int) $this->connection->table('agent_harness_runs')
            ->where('id', $run->id)
            ->lockForUpdate()
            ->value('last_event_sequence');

        $next = $current + $count;

        $this->connection->table('agent_harness_runs')
            ->where('id', $run->id)
            ->update(['last_event_sequence' => $next]);

        return $next;
    }

    /**
     * Dispatch the Laravel event once the surrounding transaction commits.
     *
     * A broadcast inside an open transaction can reach a subscriber before the
     * data it describes is readable, so it is always deferred.
     */
    protected function broadcastAfterCommit(RunEvent $event): void
    {
        $this->connection->afterCommit(function () use ($event): void {
            // Dispatched through the facade rather than an injected instance so
            // a test calling Event::fake() after this singleton is built still
            // observes the events.
            Event::dispatch(HarnessEventRecorded::fromModel($event));
        });
    }
}
