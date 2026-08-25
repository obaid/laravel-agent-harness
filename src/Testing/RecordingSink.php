<?php

declare(strict_types=1);

namespace Clutch\Laravel\Testing;

use Clutch\Laravel\Contracts\DriverEventSink;
use Clutch\Laravel\Data\DriverCheckpoint;
use Clutch\Laravel\Enums\EventType;

/**
 * An in-memory event sink for driver tests.
 *
 * Records what a driver emits without touching the database, so a driver can be
 * exercised in isolation from the coordinator.
 */
final class RecordingSink implements DriverEventSink
{
    /** @var array<int, array{type: string, payload: array<string, mixed>}> */
    public array $events = [];

    /** @var array<int, DriverCheckpoint> */
    public array $checkpoints = [];

    public function emit(EventType $type, array $payload = []): void
    {
        $this->events[] = ['type' => $type->value, 'payload' => $payload];
    }

    public function emitRaw(string $driverType, array $payload = []): void
    {
        $this->events[] = [
            'type' => EventType::DriverEvent->value,
            'payload' => ['driver_type' => $driverType, 'data' => $payload],
        ];
    }

    public function checkpoint(DriverCheckpoint $checkpoint): void
    {
        $this->checkpoints[] = $checkpoint;

        $this->emit(EventType::CheckpointCreated, ['reason' => $checkpoint->reason]);
    }

    /**
     * Every payload recorded for a given event type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payloadsOfType(string $type): array
    {
        return array_values(array_map(
            fn (array $event): array => $event['payload'],
            array_filter($this->events, fn (array $event): bool => $event['type'] === $type),
        ));
    }

    /**
     * The ordered list of event types recorded.
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_column($this->events, 'type');
    }
}
