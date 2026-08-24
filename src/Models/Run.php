<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Models;

use AgentHarness\Laravel\Approvals\ApprovalBroker;
use AgentHarness\Laravel\Enums\FailureCategory;
use AgentHarness\Laravel\Enums\RunStatus;
use AgentHarness\Laravel\Exceptions\RunNotAuthorized;
use AgentHarness\Laravel\Models\Concerns\HasHarnessId;
use AgentHarness\Laravel\Runtime\RunCoordinator;
use AgentHarness\Laravel\Support\Id;
use AgentHarness\Laravel\ValueObjects\BudgetUsage;
use AgentHarness\Laravel\ValueObjects\RunBudget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One execution attempt inside a session.
 *
 * Terminal states are immutable; retrying creates a new attempt linked to the
 * original rather than reopening this record.
 *
 * @property string $id
 * @property string $session_id
 * @property int $attempt
 * @property string|null $retry_of_run_id
 * @property string|null $idempotency_key
 * @property RunStatus $status
 * @property string $input_type
 * @property array<string, mixed>|null $input
 * @property string|null $output_text
 * @property array<string, mixed>|null $structured_output
 * @property array<string, mixed>|null $usage
 * @property numeric-string|null $cost_usd
 * @property int $last_event_sequence
 * @property string|null $last_checkpoint_id
 * @property \Illuminate\Support\Carbon|null $cancellation_requested_at
 * @property string|null $cancellation_reason
 * @property FailureCategory|null $failure_category
 * @property string|null $failure_message
 * @property string|null $failure_exception_class
 * @property array<string, mixed>|null $budget
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $queued_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $heartbeat_at
 * @property int $version
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Session|null $session
 */
class Run extends Model
{
    use HasHarnessId;

    protected $table = 'agent_harness_runs';

    protected $guarded = [];

    public function idPrefix(): string
    {
        return Id::RUN;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'failure_category' => FailureCategory::class,
            'input' => 'encrypted:array',
            'structured_output' => 'array',
            'usage' => 'array',
            'budget' => 'array',
            'metadata' => 'array',
            'cost_usd' => 'decimal:6',
            'attempt' => 'integer',
            'version' => 'integer',
            'last_event_sequence' => 'integer',
            'cancellation_requested_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    // Relations ----------------------------------------------------------

    /**
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    /**
     * @return HasMany<RunEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RunEvent::class, 'run_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'run_id');
    }

    /**
     * @return HasMany<Artifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class, 'run_id');
    }

    /**
     * @return HasMany<Checkpoint, $this>
     */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class, 'run_id');
    }

    /**
     * @return HasMany<ToolExecution, $this>
     */
    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class, 'run_id');
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'retry_of_run_id');
    }

    // Public lifecycle API -----------------------------------------------

    /**
     * Approve one pending tool call. Repeating the same decision is a no-op.
     */
    public function approve(string $approvalId, ?string $reason = null, ?object $actor = null): Approval
    {
        return app(ApprovalBroker::class)->approve($this, $approvalId, $reason, $actor);
    }

    /**
     * Reject one pending tool call. Repeating the same decision is a no-op.
     */
    public function reject(string $approvalId, ?string $reason = null, ?object $actor = null): Approval
    {
        return app(ApprovalBroker::class)->reject($this, $approvalId, $reason, $actor);
    }

    /**
     * Request cooperative cancellation.
     *
     * The harness marks the request immediately and prevents any new model step
     * or tool from starting. A tool already executing may finish.
     */
    public function cancel(?string $reason = null): self
    {
        return app(RunCoordinator::class)->requestCancellation($this, $reason);
    }

    /**
     * Create a fresh attempt of this run, linked to it.
     */
    public function retry(bool $resetBudget = false): self
    {
        return app(RunCoordinator::class)->retryRun($this, $resetBudget);
    }

    // Authorization ------------------------------------------------------

    /**
     * @throws RunNotAuthorized
     */
    public function authorizeFor(?object $participant): static
    {
        $session = $this->relationLoaded('session') ? $this->session : $this->session()->first();

        if (! $session?->belongsToParticipant($participant)) {
            throw RunNotAuthorized::forRun($this->id);
        }

        return $this;
    }

    // Queries ------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeTerminal(Builder $query): void
    {
        $query->whereIn('status', [
            RunStatus::Completed->value,
            RunStatus::Failed->value,
            RunStatus::Cancelled->value,
            RunStatus::BudgetExceeded->value,
        ]);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotIn('status', [
            RunStatus::Completed->value,
            RunStatus::Failed->value,
            RunStatus::Cancelled->value,
            RunStatus::BudgetExceeded->value,
        ]);
    }

    // Helpers ------------------------------------------------------------

    /**
     * The prompt text this run was created with.
     */
    public function promptText(): string
    {
        return (string) ($this->input['prompt'] ?? '');
    }

    /**
     * Accumulated consumption, carried across attempts.
     */
    public function usage(): BudgetUsage
    {
        return BudgetUsage::fromArray($this->usage ?? []);
    }

    /**
     * The budget recorded on this run.
     */
    public function budget(): RunBudget
    {
        return RunBudget::fromArray($this->budget ?? []);
    }

    /**
     * Approvals still waiting on a human decision.
     *
     * @return Collection<int, Approval>
     */
    public function pendingApprovals(): Collection
    {
        return $this->approvals()->pending()->get();
    }

    /**
     * Determine whether cancellation has been requested for this run.
     */
    public function isCancellationRequested(): bool
    {
        return $this->cancellation_requested_at !== null;
    }

    /**
     * Replay stored events after the given cursor.
     *
     * @return Collection<int, RunEvent>
     */
    public function eventsAfter(int $sequence, int $limit = 500): Collection
    {
        return $this->events()->where('sequence', '>', $sequence)->limit($limit)->get();
    }
}
