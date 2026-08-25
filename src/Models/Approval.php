<?php

declare(strict_types=1);

namespace Clutch\Laravel\Models;

use Clutch\Laravel\Enums\ApprovalStatus;
use Clutch\Laravel\Models\Concerns\HasHarnessId;
use Clutch\Laravel\Support\Id;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A durable request for a human decision on one tool call.
 *
 * @property string $id
 * @property string $session_id
 * @property string $run_id
 * @property string $tool_call_id
 * @property string $tool_name
 * @property array<string, mixed>|null $arguments
 * @property array<string, mixed>|null $edited_arguments
 * @property string|null $reason
 * @property ApprovalStatus $status
 * @property \Illuminate\Support\Carbon|null $requested_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property string|null $resolved_by_type
 * @property string|null $resolved_by_id
 * @property string|null $decision_reason
 * @property int $version
 */
class Approval extends Model
{
    use HasHarnessId;

    protected $table = 'clutch_approvals';

    protected $guarded = [];

    public function idPrefix(): string
    {
        return Id::APPROVAL;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'arguments' => 'encrypted:array',
            'edited_arguments' => 'encrypted:array',
            'version' => 'integer',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
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
     * @return MorphTo<Model, $this>
     */
    public function resolvedBy(): MorphTo
    {
        return $this->morphTo('resolved_by');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending->value);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function isResolved(): bool
    {
        return $this->status->isResolved();
    }

    public function isApproved(): bool
    {
        return $this->status === ApprovalStatus::Approved;
    }

    /**
     * The arguments the tool should execute with, honoring an edited decision.
     *
     * @return array<string, mixed>
     */
    public function effectiveArguments(): array
    {
        return $this->edited_arguments ?? $this->arguments ?? [];
    }
}
