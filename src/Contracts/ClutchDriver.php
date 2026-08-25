<?php

declare(strict_types=1);

namespace Clutch\Laravel\Contracts;

use Clutch\Laravel\Data\Continuation;
use Clutch\Laravel\Data\DriverCheckpoint;
use Clutch\Laravel\Data\DriverSession;
use Clutch\Laravel\Data\StartSession;
use Clutch\Laravel\Data\TurnInput;
use Clutch\Laravel\Data\TurnResult;
use Clutch\Laravel\Runtime\CancellationSignal;
use Clutch\Laravel\ValueObjects\DriverCapabilities;

/**
 * A runtime the harness can own the lifecycle of.
 *
 * Drivers never touch harness storage. They receive typed input, emit events
 * through the sink, and return a result or a checkpoint.
 */
interface ClutchDriver
{
    /**
     * The name this driver is registered under.
     */
    public function name(): string;

    /**
     * Truthfully declare what this driver supports.
     *
     * A mismatch between a declared and an actual capability is a driver bug.
     */
    public function capabilities(): DriverCapabilities;

    /**
     * Begin a new session and return the driver's handle on it.
     */
    public function start(StartSession $command): DriverSession;

    /**
     * Process one new unit of input.
     */
    public function runTurn(
        DriverSession $session,
        TurnInput $input,
        DriverEventSink $events,
        CancellationSignal $cancellation,
    ): TurnResult;

    /**
     * Resume a paused turn with resolved approval decisions.
     */
    public function continueTurn(
        DriverSession $session,
        Continuation $continuation,
        DriverEventSink $events,
        CancellationSignal $cancellation,
    ): TurnResult;

    /**
     * Capture a restorable snapshot of the session's state.
     */
    public function checkpoint(DriverSession $session): DriverCheckpoint;

    /**
     * Rebuild a session handle from a previously captured snapshot.
     */
    public function restore(DriverCheckpoint $checkpoint): DriverSession;

    /**
     * Stop the session, returning a final snapshot.
     */
    public function stop(DriverSession $session): DriverCheckpoint;

    /**
     * Release every resource the session holds.
     */
    public function destroy(DriverSession $session): void;
}
