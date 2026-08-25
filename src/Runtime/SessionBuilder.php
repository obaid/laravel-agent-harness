<?php

declare(strict_types=1);

namespace Clutch\Laravel\Runtime;

use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\ValueObjects\RunBudget;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The fluent entry point for creating a durable session.
 *
 * Clone-on-write: every method returns a new builder, so a partially
 * configured builder can be safely shared and specialized.
 */
class SessionBuilder
{
    protected ?string $agentClass = null;

    protected ?string $runtimeName = null;

    protected ?string $driver = null;

    protected ?string $name = null;

    protected ?object $participant = null;

    protected ?object $tenant = null;

    protected PermissionMode $permissionMode;

    protected ?RunBudget $budget = null;

    /** @var array<string, mixed> */
    protected array $configuration = [];

    /** @var array<string, mixed> */
    protected array $metadata = [];

    protected ?string $queueConnection = null;

    protected ?string $queue = null;

    protected ?int $timeoutSeconds = null;

    protected ?string $workspaceId = null;

    protected ?string $sandbox = null;

    public function __construct(protected RunCoordinator $coordinator)
    {
        $this->permissionMode = PermissionMode::tryFrom(
            (string) config('clutch.permissions.default', PermissionMode::ApproveSensitive->value)
        ) ?? PermissionMode::ApproveSensitive;
    }

    /**
     * Use a Laravel AI agent class.
     *
     * @param  class-string  $agentClass
     */
    public function agent(string $agentClass): static
    {
        if (! class_exists($agentClass)) {
            throw new InvalidArgumentException("The agent class [{$agentClass}] does not exist.");
        }

        return tap(clone $this, function (self $builder) use ($agentClass): void {
            $builder->agentClass = $agentClass;
        });
    }

    /**
     * Use a named runtime instead of a Laravel AI agent class.
     */
    public function runtime(string $runtime): static
    {
        return tap(clone $this, function (self $builder) use ($runtime): void {
            $builder->runtimeName = $runtime;
            $builder->driver ??= $runtime;
        });
    }

    /**
     * Select the harness driver explicitly.
     */
    public function driver(string $driver): static
    {
        return tap(clone $this, function (self $builder) use ($driver): void {
            $builder->driver = $driver;
        });
    }

    /**
     * The user or model this session belongs to.
     */
    public function for(?object $participant): static
    {
        return tap(clone $this, function (self $builder) use ($participant): void {
            $builder->participant = $participant;
        });
    }

    /**
     * The tenant this session is scoped to.
     */
    public function tenant(?object $tenant): static
    {
        return tap(clone $this, function (self $builder) use ($tenant): void {
            $builder->tenant = $tenant;
        });
    }

    /**
     * A human-readable label for the session.
     */
    public function name(string $name): static
    {
        return tap(clone $this, function (self $builder) use ($name): void {
            $builder->name = $name;
        });
    }

    /**
     * The approval policy applied to this session's tools.
     */
    public function permissions(PermissionMode $mode): static
    {
        return tap(clone $this, function (self $builder) use ($mode): void {
            $builder->permissionMode = $mode;
        });
    }

    /**
     * Hard limits for every run in this session.
     */
    public function budget(RunBudget $budget): static
    {
        return tap(clone $this, function (self $builder) use ($budget): void {
            $builder->budget = $budget;
        });
    }

    /**
     * Driver configuration, such as provider or model overrides.
     *
     * @param  array<string, mixed>|string  $key
     */
    public function configure(array|string $key, mixed $value = null): static
    {
        return tap(clone $this, function (self $builder) use ($key, $value): void {
            $builder->configuration = is_array($key)
                ? [...$builder->configuration, ...$key]
                : [...$builder->configuration, $key => $value];
        });
    }

    /**
     * Application metadata stored alongside the session.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): static
    {
        return tap(clone $this, function (self $builder) use ($metadata): void {
            $builder->metadata = [...$builder->metadata, ...$metadata];
        });
    }

    /**
     * Pin the provider Laravel AI should use for this session.
     *
     * @param  array<string, string|null>|string  $provider
     */
    public function usingProvider(array|string $provider): static
    {
        return $this->configure('provider', $provider);
    }

    /**
     * Pin the model Laravel AI should use for this session.
     */
    public function usingModel(string $model): static
    {
        return $this->configure('model', $model);
    }

    public function onConnection(?string $connection): static
    {
        return tap(clone $this, function (self $builder) use ($connection): void {
            $builder->queueConnection = $connection;
        });
    }

    public function onQueue(?string $queue): static
    {
        return tap(clone $this, function (self $builder) use ($queue): void {
            $builder->queue = $queue;
        });
    }

    /**
     * The per-turn provider timeout in seconds.
     */
    public function timeout(int $seconds): static
    {
        return tap(clone $this, function (self $builder) use ($seconds): void {
            $builder->timeoutSeconds = $seconds;
        });
    }

    /**
     * Attach a workspace for runtimes that need a filesystem.
     */
    public function workspace(object|string $workspace): static
    {
        return tap(clone $this, function (self $builder) use ($workspace): void {
            $builder->workspaceId = $workspace instanceof Model
                ? (string) $workspace->getKey()
                : (string) $workspace;
        });
    }

    /**
     * Request a sandbox provider for runtimes that need process isolation.
     */
    public function sandbox(string $provider): static
    {
        return tap(clone $this, function (self $builder) use ($provider): void {
            $builder->sandbox = $provider;
        });
    }

    /**
     * Persist the session and bring its driver online.
     *
     * Cross-resource constraints are validated here rather than at each setter,
     * so a builder can be assembled in any order.
     */
    public function create(): Session
    {
        if ($this->agentClass === null && $this->runtimeName === null) {
            throw new InvalidArgumentException(
                'A Clutch session needs either an agent class or a runtime. '.
                'Call Clutch::agent(YourAgent::class) or Clutch::runtime(\'name\').'
            );
        }

        return $this->coordinator->createSession([
            'agent_class' => $this->agentClass,
            'runtime_name' => $this->runtimeName,
            'driver' => $this->driver ?? (string) config('clutch.default_driver', 'laravel-ai'),
            'name' => $this->name,
            'permission_mode' => $this->permissionMode,
            'configuration' => $this->configurationPayload(),
            'budget' => $this->budget?->toArray(),
            'metadata' => $this->metadata === [] ? null : $this->metadata,
            'participant_type' => $this->participant instanceof Model ? $this->participant->getMorphClass() : null,
            'participant_id' => $this->participant instanceof Model ? (string) $this->participant->getKey() : null,
            'tenant_type' => $this->tenant instanceof Model ? $this->tenant->getMorphClass() : null,
            'tenant_id' => $this->tenant instanceof Model ? (string) $this->tenant->getKey() : null,
            'queue_connection' => $this->queueConnection,
            'queue' => $this->queue,
            'timeout_seconds' => $this->timeoutSeconds,
            'workspace_id' => $this->workspaceId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function configurationPayload(): array
    {
        return array_filter([
            ...$this->configuration,
            'timeout' => $this->timeoutSeconds,
            'sandbox' => $this->sandbox,
        ], fn (mixed $value): bool => $value !== null);
    }
}
