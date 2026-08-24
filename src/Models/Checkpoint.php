<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Models;

use AgentHarness\Laravel\Data\DriverCheckpoint;
use AgentHarness\Laravel\Models\Concerns\HasHarnessId;
use AgentHarness\Laravel\Support\Id;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A versioned, encrypted snapshot of driver state.
 *
 * Payload contents are opaque to the coordinator beyond the common metadata,
 * and must never contain secrets.
 *
 * @property string $id
 * @property string $session_id
 * @property string|null $run_id
 * @property string $driver
 * @property int $schema_version
 * @property string $reason
 * @property array<string, mixed> $payload
 * @property string $payload_digest
 * @property int $event_sequence
 * @property bool $portable
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class Checkpoint extends Model
{
    use HasHarnessId;

    public $timestamps = false;

    protected $table = 'agent_harness_checkpoints';

    protected $guarded = [];

    public function idPrefix(): string
    {
        return Id::CHECKPOINT;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'schema_version' => 'integer',
            'event_sequence' => 'integer',
            'portable' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /**
     * Rebuild the driver-facing value object.
     */
    public function toDriverCheckpoint(): DriverCheckpoint
    {
        return new DriverCheckpoint(
            driver: $this->driver,
            schemaVersion: $this->schema_version,
            payload: $this->payload ?? [],
            reason: $this->reason,
            portable: (bool) $this->portable,
            sessionId: $this->session_id,
            runId: $this->run_id,
        );
    }

    /**
     * Verify the stored payload still matches its recorded digest.
     */
    public function hasIntactPayload(): bool
    {
        return hash('sha256', (string) json_encode($this->payload ?? [])) === $this->payload_digest;
    }
}
