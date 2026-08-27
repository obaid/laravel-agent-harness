<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\ClutchResult;
use Clutch\Laravel\ValueObjects\RunBudget;

/**
 * Configures a workflow run before it starts.
 *
 * The same knobs a session takes, because a workflow run is a session: who it
 * belongs to, what it may spend, which queue carries it.
 */
final class PendingWorkflow
{
    protected ?object $participant = null;

    protected ?object $tenant = null;

    protected ?RunBudget $budget = null;

    protected ?string $name = null;

    protected ?string $connection = null;

    protected ?string $queue = null;

    protected ?int $timeout = null;

    protected PermissionMode $permissions = PermissionMode::ApproveSensitive;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    /** @var array<string, int> */
    protected array $limits = [];

    /**
     * @param  class-string<Workflow>  $workflow
     */
    public function __construct(protected string $workflow) {}

    public function for(?object $participant): self
    {
        $this->participant = $participant;

        return $this;
    }

    public function tenant(?object $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * The ceiling for the whole job, agent prompts included.
     */
    public function budget(RunBudget $budget): self
    {
        $this->budget = $budget;

        return $this;
    }

    /**
     * The mode the workflow's agent sessions inherit.
     */
    public function permissions(PermissionMode $mode): self
    {
        $this->permissions = $mode;

        return $this;
    }

    public function onConnection(?string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function onQueue(?string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Hand each turn back after this many executed steps.
     *
     * The run suspends at the boundary and a continuation re-enters with the
     * finished steps cached, so the workflow advances one slice per queue job.
     */
    public function sliceAfterSteps(int $steps): self
    {
        $this->limits['max_steps_per_slice'] = max(1, $steps);

        return $this;
    }

    /**
     * Hand each turn back at the first step boundary past this wall-clock
     * budget.
     *
     * Size it below the queue worker's timeout so a workflow longer than any
     * single worker's lifetime parks itself deliberately and completes as a
     * chain of sub-timeout jobs, instead of being killed mid-flight.
     */
    public function sliceAfterSeconds(int $seconds): self
    {
        $this->limits['max_seconds_per_slice'] = max(1, $seconds);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = [...$this->metadata, ...$metadata];

        return $this;
    }

    /**
     * Queue the workflow and return its run straight away.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(array $payload = []): Run
    {
        return app(WorkflowRunner::class)->queue($this->definition(), $payload);
    }

    /**
     * Run the workflow inline and return when it settles.
     *
     * @param  array<string, mixed>  $payload
     */
    public function runNow(array $payload = []): ClutchResult
    {
        return app(WorkflowRunner::class)->runNow($this->definition(), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function definition(): array
    {
        return [
            'workflow' => $this->workflow,
            'participant' => $this->participant,
            'tenant' => $this->tenant,
            'name' => $this->name ?? class_basename($this->workflow),
            'budget' => $this->budget,
            'permission_mode' => $this->permissions,
            'queue_connection' => $this->connection,
            'queue' => $this->queue,
            'timeout_seconds' => $this->timeout,
            'metadata' => $this->metadata,
            'limits' => $this->limits,
        ];
    }
}
