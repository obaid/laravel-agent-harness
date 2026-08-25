<?php

declare(strict_types=1);

namespace Clutch\Laravel\Models;

use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Models\Concerns\HasHarnessId;
use Clutch\Laravel\Support\Id;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only fact emitted during a run.
 *
 * Events are never updated or individually deleted before retention pruning.
 * Ordering is guaranteed within one run by the `(run_id, sequence)` unique key.
 *
 * @property string $id
 * @property string $session_id
 * @property string $run_id
 * @property int $sequence
 * @property EventType $type
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon|null $occurred_at
 */
class RunEvent extends Model
{
    use HasHarnessId;

    public $timestamps = false;

    protected $table = 'clutch_events';

    protected $guarded = [];

    public function idPrefix(): string
    {
        return Id::EVENT;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'payload' => 'array',
            'sequence' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /**
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    /**
     * The wire envelope every consumer receives, whatever the transport.
     *
     * @return array<string, mixed>
     */
    public function toEnvelope(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'run_id' => $this->run_id,
            'sequence' => $this->sequence,
            'type' => $this->type->value,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'payload' => $this->payload ?? [],
        ];
    }

    /**
     * Determine whether this event closes the run's stream.
     */
    public function isTerminal(): bool
    {
        return $this->type->isTerminal();
    }
}
