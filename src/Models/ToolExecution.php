<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Models;

use AgentHarness\Laravel\Models\Concerns\HasHarnessId;
use AgentHarness\Laravel\Support\Id;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The ledger entry that keeps a side effect from happening twice.
 *
 * @property string $id
 * @property string $session_id
 * @property string $run_id
 * @property string $tool_call_id
 * @property string $tool_name
 * @property string|null $idempotency_key
 * @property string $arguments_digest
 * @property string $status
 * @property string|null $result
 * @property string|null $error_message
 * @property int|null $duration_ms
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class ToolExecution extends Model
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    use HasHarnessId;

    protected $table = 'agent_harness_tool_executions';

    protected $guarded = [];

    public function idPrefix(): string
    {
        return Id::TOOL_CALL;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => 'encrypted',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @param  Builder<$this>  $query
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', self::COMPLETED);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }
}
