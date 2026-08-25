<?php

declare(strict_types=1);

namespace Clutch\Laravel\Events;

use Clutch\Laravel\Models\RunEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after every harness event is durably recorded.
 *
 * Applications listen to this for auditing, analytics, metering, notifications
 * or custom broadcasting without depending on any transport format.
 */
class ClutchEventRecorded implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly string $runId,
        public readonly int $sequence,
        public readonly string $type,
        public readonly array $envelope,
    ) {}

    /**
     * Build the event from a persisted record.
     */
    public static function fromModel(RunEvent $event): self
    {
        return new self(
            sessionId: $event->session_id,
            runId: $event->run_id,
            sequence: $event->sequence,
            type: $event->type->value,
            envelope: $event->toEnvelope(),
        );
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if (config('clutch.events.broadcast', true) !== true) {
            return [];
        }

        return [
            new PrivateChannel("clutch.run.{$this->runId}"),
            new PrivateChannel("clutch.session.{$this->sessionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->envelope;
    }
}
