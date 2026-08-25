<?php

declare(strict_types=1);

namespace Clutch\Laravel\Contracts;

use Clutch\Laravel\Data\DriverCheckpoint;
use Clutch\Laravel\Enums\EventType;

/**
 * The channel a driver uses to report progress while a turn is in flight.
 *
 * Implementations persist before broadcasting so a reconnecting client can
 * always replay what a live subscriber saw.
 */
interface DriverEventSink
{
    /**
     * Record one normalized event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(EventType $type, array $payload = []): void;

    /**
     * Record an un-normalized runtime event. Payloads are redacted before persistence.
     *
     * @param  array<string, mixed>  $payload
     */
    public function emitRaw(string $driverType, array $payload = []): void;

    /**
     * Persist a checkpoint at a safe boundary mid-turn.
     */
    public function checkpoint(DriverCheckpoint $checkpoint): void;
}
