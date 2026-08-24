<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Models;

use AgentHarness\Laravel\Enums\PermissionMode;
use AgentHarness\Laravel\Enums\SessionStatus;
use AgentHarness\Laravel\Exceptions\RunNotAuthorized;
use AgentHarness\Laravel\Models\Concerns\HasHarnessId;
use AgentHarness\Laravel\Runtime\HarnessResult;
use AgentHarness\Laravel\Runtime\RunCoordinator;
use AgentHarness\Laravel\Streaming\StreamedRun;
use AgentHarness\Laravel\Support\Id;
use AgentHarness\Laravel\ValueObjects\RunBudget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Durable agent identity: context, configuration, and optional workspace.
 *
 * A session may contain many sequential runs but only one active run at a time.
 * Lifecycle mutation happens through the coordinator, never by assigning to
 * `status` directly.
 *
 * @property string $id
 * @property string|null $tenant_type
 * @property string|null $tenant_id
 * @property string|null $participant_type
 * @property string|null $participant_id
 * @property class-string|null $agent_class
 * @property string|null $runtime_name
 * @property string $driver
 * @property string|null $name
 * @property SessionStatus $status
 * @property PermissionMode $permission_mode
 * @property string|null $conversation_id
 * @property string|null $workspace_id
 * @property array<string, mixed>|null $configuration
 * @property array<string, mixed>|null $budget
 * @property array<string, mixed>|null $metadata
 * @property string|null $active_run_id
 * @property string|null $queue_connection
 * @property string|null $queue
 * @property int|null $timeout_seconds
 * @property int $version
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Session extends Model
{
    use HasHarnessId;
    use SoftDeletes;

    protected $table = 'agent_harness_sessions';

    protected $guarded = [];

    public function idPrefix(): string
    {
        return Id::SESSION;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'permission_mode' => PermissionMode::class,
            'configuration' => 'encrypted:array',
            'budget' => 'array',
            'metadata' => 'array',
            'version' => 'integer',
            'timeout_seconds' => 'integer',
        ];
    }

    // Relations ----------------------------------------------------------

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class, 'session_id')->latest('created_at');
    }

    /**
     * @return HasOne<Run, $this>
     */
    public function activeRun(): HasOne
    {
        return $this->hasOne(Run::class, 'id', 'active_run_id');
    }

    /**
     * @return HasMany<RunEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RunEvent::class, 'session_id');
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'session_id');
    }

    /**
     * @return HasMany<Checkpoint, $this>
     */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class, 'session_id');
    }

    /**
     * @return HasMany<Artifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class, 'session_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function participant(): MorphTo
    {
        return $this->morphTo('participant');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function tenant(): MorphTo
    {
        return $this->morphTo('tenant');
    }

    // Public lifecycle API -----------------------------------------------

    /**
     * Run the agent synchronously and wait for its terminal result.
     */
    /**
     * @param  array<int, mixed>  $attachments
     * @param  array<string, mixed>  $options
     */
    public function prompt(string $prompt, array $attachments = [], array $options = []): HarnessResult
    {
        return $this->coordinator()->promptNow($this, $prompt, $attachments, $options);
    }

    /**
     * Create a run and hand it to the queue.
     */
    /**
     * @param  array<int, mixed>  $attachments
     * @param  array<string, mixed>  $options
     */
    public function queue(string $prompt, array $attachments = [], array $options = []): Run
    {
        return $this->coordinator()->queueRun($this, $prompt, $attachments, $options);
    }

    /**
     * Run the agent synchronously, streaming normalized events as they are recorded.
     */
    /**
     * @param  array<int, mixed>  $attachments
     * @param  array<string, mixed>  $options
     */
    public function stream(string $prompt, array $attachments = [], array $options = []): StreamedRun
    {
        return $this->coordinator()->streamRun($this, $prompt, $attachments, $options);
    }

    /**
     * Stop the session, releasing driver and workspace resources.
     */
    public function stop(): self
    {
        return $this->coordinator()->stopSession($this);
    }

    /**
     * Permanently destroy the session and its runtime resources.
     */
    public function destroySession(): void
    {
        $this->coordinator()->destroySession($this);
    }

    // Authorization ------------------------------------------------------

    /**
     * Assert that the given participant owns this session.
     *
     * @throws RunNotAuthorized
     */
    public function authorizeFor(?object $participant): static
    {
        if (! $this->belongsToParticipant($participant)) {
            throw RunNotAuthorized::forSession($this->id);
        }

        return $this;
    }

    /**
     * Determine whether the given participant owns this session.
     */
    public function belongsToParticipant(?object $participant): bool
    {
        if ($participant === null) {
            return $this->participant_id === null;
        }

        if (! $participant instanceof Model) {
            return false;
        }

        return $this->participant_type === $participant->getMorphClass()
            && (string) $this->participant_id === (string) $participant->getKey();
    }

    // Queries ------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForParticipant(Builder $query, object $participant): void
    {
        $query->where('participant_type', $participant instanceof Model ? $participant->getMorphClass() : $participant::class)
            ->where('participant_id', $participant instanceof Model ? (string) $participant->getKey() : null);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForTenant(Builder $query, object $tenant): void
    {
        $query->where('tenant_type', $tenant instanceof Model ? $tenant->getMorphClass() : $tenant::class)
            ->where('tenant_id', $tenant instanceof Model ? (string) $tenant->getKey() : null);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithStatus(Builder $query, SessionStatus ...$statuses): void
    {
        $query->whereIn('status', array_map(fn (SessionStatus $s): string => $s->value, $statuses));
    }

    // Helpers ------------------------------------------------------------

    /**
     * The effective budget for this session, merged over configured defaults.
     */
    public function budget(): RunBudget
    {
        return RunBudget::fromArray($this->budget ?? []);
    }

    /**
     * Determine whether a run is currently occupying this session.
     */
    public function hasActiveRun(): bool
    {
        return $this->active_run_id !== null;
    }

    protected function coordinator(): RunCoordinator
    {
        return app(RunCoordinator::class);
    }
}
