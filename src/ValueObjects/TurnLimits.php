<?php

declare(strict_types=1);

namespace Clutch\Laravel\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * How much work a driver may do before handing the turn back.
 *
 * This is not a budget. A budget is a ceiling that ends a run when it is
 * reached; these limits end a *slice* and leave the run alive to continue in
 * the next job. That difference is why a run can be limited to sixty seconds
 * per worker without being limited to sixty seconds in total.
 *
 * @implements Arrayable<string, int|null>
 */
final readonly class TurnLimits implements Arrayable
{
    public function __construct(
        /** Model/tool steps to run before suspending. Null runs to completion. */
        public ?int $maxStepsPerSlice = null,
        /** Wall-clock seconds to work before suspending at the next boundary. */
        public ?int $maxSecondsPerSlice = null,
    ) {}

    /**
     * Run the turn to completion, the default.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * Suspend after a fixed number of steps.
     *
     * One step per slice gives a workflow engine a checkpoint between every
     * model call, which is the finest granularity a driver can offer.
     */
    public static function steps(int $steps): self
    {
        return new self(maxStepsPerSlice: max(1, $steps));
    }

    /**
     * Suspend at the first safe boundary after the given wall-clock budget.
     *
     * Size this below the queue worker's timeout so the worker parks the run
     * deliberately rather than being killed mid-turn.
     */
    public static function seconds(int $seconds): self
    {
        return new self(maxSecondsPerSlice: max(1, $seconds));
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    public static function fromArray(array $limits): self
    {
        $steps = $limits['max_steps_per_slice'] ?? $limits['maxStepsPerSlice'] ?? null;
        $seconds = $limits['max_seconds_per_slice'] ?? $limits['maxSecondsPerSlice'] ?? null;

        return new self(
            maxStepsPerSlice: $steps === null ? null : (int) $steps,
            maxSecondsPerSlice: $seconds === null ? null : (int) $seconds,
        );
    }

    /**
     * Determine whether any limit is set.
     */
    public function isBounded(): bool
    {
        return $this->maxStepsPerSlice !== null || $this->maxSecondsPerSlice !== null;
    }

    /**
     * Determine whether a slice that has run this far should hand the turn back.
     */
    public function reached(int $steps, float $elapsedSeconds): bool
    {
        if ($this->maxStepsPerSlice !== null && $steps >= $this->maxStepsPerSlice) {
            return true;
        }

        return $this->maxSecondsPerSlice !== null && $elapsedSeconds >= $this->maxSecondsPerSlice;
    }

    /**
     * Name the limit that ended the slice, for the event payload.
     */
    public function reasonFor(int $steps, float $elapsedSeconds): ?string
    {
        if ($this->maxStepsPerSlice !== null && $steps >= $this->maxStepsPerSlice) {
            return 'max_steps_per_slice';
        }

        if ($this->maxSecondsPerSlice !== null && $elapsedSeconds >= $this->maxSecondsPerSlice) {
            return 'max_seconds_per_slice';
        }

        return null;
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'max_steps_per_slice' => $this->maxStepsPerSlice,
            'max_seconds_per_slice' => $this->maxSecondsPerSlice,
        ];
    }
}
