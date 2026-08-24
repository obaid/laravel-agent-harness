<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Checkpoints;

use AgentHarness\Laravel\Data\DriverCheckpoint;
use AgentHarness\Laravel\Exceptions\CheckpointIncompatible;
use AgentHarness\Laravel\Models\Checkpoint;
use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Models\Session;
use AgentHarness\Laravel\Runtime\Redactor;
use AgentHarness\Laravel\Support\Id;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Persists versioned, encrypted driver checkpoints.
 *
 * Payloads are opaque to the coordinator beyond their common metadata. Secrets
 * are resolved immediately before use and never checkpointed; this store
 * enforces that rather than trusting drivers to remember.
 */
class CheckpointStore
{
    public function __construct(protected Redactor $redactor) {}

    /**
     * Store a checkpoint for a session, optionally scoped to a run.
     */
    public function store(Session $session, ?Run $run, DriverCheckpoint $checkpoint): Checkpoint
    {
        $this->assertContainsNoSecrets($checkpoint);

        return Checkpoint::query()->create([
            'id' => Id::checkpoint(),
            'session_id' => $session->id,
            'run_id' => $run?->id,
            'driver' => $checkpoint->driver,
            'schema_version' => $checkpoint->schemaVersion,
            'reason' => $checkpoint->reason,
            'payload' => $checkpoint->payload,
            'payload_digest' => $checkpoint->digest(),
            'event_sequence' => (int) ($run->last_event_sequence ?? 0),
            'portable' => $checkpoint->portable,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * The most recent checkpoint for a session, whichever run produced it.
     */
    public function latestForSession(string $sessionId): ?Checkpoint
    {
        return Checkpoint::query()
            ->where('session_id', $sessionId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The most recent checkpoint recorded during a specific run.
     */
    public function latestForRun(string $runId): ?Checkpoint
    {
        return Checkpoint::query()
            ->where('run_id', $runId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The last safe point a session can resume from: mid-run progress if there
     * is any, otherwise the between-turn checkpoint.
     */
    public function resumePointFor(Session $session, ?Run $run = null): ?Checkpoint
    {
        if ($run !== null) {
            $checkpoint = $this->latestForRun($run->id);

            if ($checkpoint !== null) {
                return $checkpoint;
            }
        }

        return $this->latestForSession($session->id);
    }

    /**
     * Rebuild a driver checkpoint, verifying it is compatible and intact.
     *
     * @throws CheckpointIncompatible
     */
    public function toDriverCheckpoint(Checkpoint $checkpoint, string $driver, int $expectedSchemaVersion): DriverCheckpoint
    {
        if ($checkpoint->driver !== $driver) {
            throw new CheckpointIncompatible(
                "A checkpoint written by the [{$checkpoint->driver}] driver cannot be restored by [{$driver}]."
            );
        }

        if ($checkpoint->schema_version > $expectedSchemaVersion) {
            throw CheckpointIncompatible::forSchema($driver, $checkpoint->schema_version, $expectedSchemaVersion);
        }

        if (! $checkpoint->hasIntactPayload()) {
            throw new CheckpointIncompatible(
                "The stored payload for checkpoint [{$checkpoint->id}] does not match its recorded digest."
            );
        }

        return $checkpoint->toDriverCheckpoint();
    }

    /**
     * Refuse to persist a checkpoint carrying a configured secret key.
     *
     * This is a hard invariant, so it throws rather than silently redacting:
     * a driver that leaks a secret into its state has a bug the author needs
     * to see, and a quietly stripped value would corrupt the restore.
     */
    protected function assertContainsNoSecrets(DriverCheckpoint $checkpoint): void
    {
        if ($this->redactor->containsSensitiveKeys($checkpoint->payload)) {
            throw new LogicException(
                "The [{$checkpoint->driver}] driver attempted to checkpoint a payload containing a ".
                'configured sensitive key. Secrets must be resolved at runtime and never checkpointed.'
            );
        }
    }
}
