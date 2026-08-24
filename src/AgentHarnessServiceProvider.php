<?php

declare(strict_types=1);

namespace AgentHarness\Laravel;

use AgentHarness\Laravel\Approvals\ApprovalBroker;
use AgentHarness\Laravel\Artifacts\ArtifactManager;
use AgentHarness\Laravel\Budgets\BudgetManager;
use AgentHarness\Laravel\Budgets\CostEstimator;
use AgentHarness\Laravel\Checkpoints\CheckpointStore;
use AgentHarness\Laravel\Console\CancelRunCommand;
use AgentHarness\Laravel\Console\EventsCommand;
use AgentHarness\Laravel\Console\MakeHarnessAgentCommand;
use AgentHarness\Laravel\Console\PruneCommand;
use AgentHarness\Laravel\Console\ReapCommand;
use AgentHarness\Laravel\Console\RetryRunCommand;
use AgentHarness\Laravel\Console\RunCommand;
use AgentHarness\Laravel\Console\SessionsCommand;
use AgentHarness\Laravel\Contracts\SandboxProvider;
use AgentHarness\Laravel\Drivers\LaravelAi\EventTranslator;
use AgentHarness\Laravel\Jobs\ExpireApprovals;
use AgentHarness\Laravel\Jobs\PruneAgentHarnessRecords;
use AgentHarness\Laravel\Jobs\ReapAbandonedRuns;
use AgentHarness\Laravel\Leases\LeaseManager;
use AgentHarness\Laravel\Policies\PolicyAwareTools;
use AgentHarness\Laravel\Policies\PolicyEngine;
use AgentHarness\Laravel\Runtime\DriverRegistry;
use AgentHarness\Laravel\Runtime\EventStore;
use AgentHarness\Laravel\Runtime\Redactor;
use AgentHarness\Laravel\Runtime\RunCoordinator;
use AgentHarness\Laravel\Sandbox\NullSandboxProvider;
use AgentHarness\Laravel\Streaming\EventStreamResponse;
use AgentHarness\Laravel\Tools\ToolExecutionLedger;
use AgentHarness\Laravel\ValueObjects\RunBudget;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AgentHarnessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-harness.php', 'agent-harness');

        $this->registerSupportServices();
        $this->registerRuntime();
    }

    public function boot(): void
    {
        $this->publishesResources();
        $this->registerRoutes();
        $this->registerBroadcastChannels();
        $this->registerCommands();
        $this->registerScheduledWork();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function registerSupportServices(): void
    {
        $this->app->singleton(Redactor::class, fn ($app): Redactor => new Redactor(
            sensitiveKeys: (array) $app['config']->get('agent-harness.events.redact', []),
            toolSerializers: (array) $app['config']->get('agent-harness.events.serializers', []),
        ));

        $this->app->singleton(CostEstimator::class, fn ($app): CostEstimator => new CostEstimator(
            (array) $app['config']->get('agent-harness.pricing', []),
        ));

        $this->app->singleton(BudgetManager::class, fn ($app): BudgetManager => new BudgetManager(
            RunBudget::fromArray((array) $app['config']->get('agent-harness.budgets', [])),
        ));

        $this->app->singleton(LeaseManager::class, fn ($app): LeaseManager => new LeaseManager(
            cache: $app['cache'],
            store: $app['config']->get('agent-harness.leases.store'),
            ttlSeconds: (int) $app['config']->get('agent-harness.leases.ttl_seconds', 60),
            heartbeatSeconds: (int) $app['config']->get('agent-harness.leases.heartbeat_seconds', 15),
        ));

        $this->app->singleton(PolicyEngine::class, fn ($app): PolicyEngine => new PolicyEngine(
            sensitivityMap: (array) $app['config']->get('agent-harness.permissions.tools', []),
            alwaysAllow: (array) $app['config']->get('agent-harness.permissions.always_allow', []),
        ));

        $this->app->singleton(PolicyAwareTools::class);
        $this->app->singleton(CheckpointStore::class);
        $this->app->singleton(ToolExecutionLedger::class);
        $this->app->singleton(EventTranslator::class);

        $this->app->singleton(SandboxProvider::class, NullSandboxProvider::class);

        $this->app->singleton(EventStore::class, fn ($app): EventStore => new EventStore(
            connection: $app['db']->connection(),
            redactor: $app->make(Redactor::class),
            persistDeltas: (bool) $app['config']->get('agent-harness.events.persist_deltas', true),
        ));

        $this->app->singleton(ApprovalBroker::class, fn ($app): ApprovalBroker => new ApprovalBroker(
            connection: $app['db']->connection(),
            events: $app->make(EventStore::class),
            expiresAfterSeconds: $app['config']->get('agent-harness.approvals.expires_after') !== null
                ? (int) $app['config']->get('agent-harness.approvals.expires_after')
                : null,
        ));

        $this->app->singleton(ArtifactManager::class);

        $this->app->singleton(EventStreamResponse::class, fn ($app): EventStreamResponse => new EventStreamResponse(
            events: $app->make(EventStore::class),
            pollIntervalMicroseconds: (int) $app['config']->get('agent-harness.streaming.poll_interval_ms', 250) * 1000,
            keepAliveSeconds: (int) $app['config']->get('agent-harness.streaming.keep_alive_seconds', 15),
            maxDurationSeconds: (int) $app['config']->get('agent-harness.streaming.max_duration_seconds', 300),
        ));
    }

    protected function registerRuntime(): void
    {
        $this->app->singleton(DriverRegistry::class, fn ($app): DriverRegistry => new DriverRegistry(
            container: $app,
            config: (array) $app['config']->get('agent-harness.drivers', []),
            default: (string) $app['config']->get('agent-harness.default_driver', 'laravel-ai'),
        ));

        $this->app->singleton(RunCoordinator::class, fn ($app): RunCoordinator => new RunCoordinator(
            connection: $app['db']->connection(),
            drivers: $app->make(DriverRegistry::class),
            events: $app->make(EventStore::class),
            checkpoints: $app->make(CheckpointStore::class),
            leases: $app->make(LeaseManager::class),
            budgets: $app->make(BudgetManager::class),
            approvals: $app->make(ApprovalBroker::class),
            artifacts: $app->make(ArtifactManager::class),
            redactor: $app->make(Redactor::class),
            logger: $app['log'],
        ));

        $this->app->singleton(HarnessManager::class, fn ($app): HarnessManager => new HarnessManager(
            container: $app,
            coordinator: $app->make(RunCoordinator::class),
            drivers: $app->make(DriverRegistry::class),
        ));

        $this->app->alias(HarnessManager::class, 'agent-harness');
    }

    protected function publishesResources(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/agent-harness.php' => config_path('agent-harness.php'),
        ], ['agent-harness', 'agent-harness-config']);

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['agent-harness', 'agent-harness-migrations']);
    }

    protected function registerRoutes(): void
    {
        if ($this->app['config']->get('agent-harness.routes.enabled', true) !== true) {
            return;
        }

        Route::group([
            'prefix' => $this->app['config']->get('agent-harness.routes.prefix', 'api/agent-harness'),
            'middleware' => $this->app['config']->get('agent-harness.routes.middleware', ['api']),
            'as' => 'agent-harness.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/agent-harness.php');
        });
    }

    /**
     * Authorize the run and session broadcast channels by participant.
     *
     * Membership is checked against the owning session, so a broadcast can
     * never leak across tenants.
     */
    protected function registerBroadcastChannels(): void
    {
        if ($this->app['config']->get('agent-harness.events.broadcast', true) !== true) {
            return;
        }

        if (! $this->app->bound(\Illuminate\Contracts\Broadcasting\Factory::class)) {
            return;
        }

        Broadcast::channel('agent-harness.run.{runId}', function ($user, string $runId): bool {
            $run = Models\Run::query()->with('session')->find($runId);

            return $run?->session?->belongsToParticipant($user) ?? false;
        });

        Broadcast::channel('agent-harness.session.{sessionId}', function ($user, string $sessionId): bool {
            return Models\Session::query()->find($sessionId)?->belongsToParticipant($user) ?? false;
        });
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            SessionsCommand::class,
            RunCommand::class,
            EventsCommand::class,
            CancelRunCommand::class,
            RetryRunCommand::class,
            PruneCommand::class,
            ReapCommand::class,
            MakeHarnessAgentCommand::class,
        ]);
    }

    /**
     * Register the background work the harness needs to stay healthy.
     */
    protected function registerScheduledWork(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $config = $this->app['config'];

            $schedule->job(new ReapAbandonedRuns(
                staleAfterSeconds: (int) $config->get('agent-harness.recovery.stale_after_seconds', 300),
                retry: (bool) $config->get('agent-harness.recovery.retry_abandoned', true),
            ))->everyFiveMinutes()->name('agent-harness:reap')->withoutOverlapping();

            if ($config->get('agent-harness.approvals.expires_after') !== null) {
                $schedule->job(new ExpireApprovals)
                    ->everyFiveMinutes()->name('agent-harness:expire-approvals')->withoutOverlapping();
            }

            $schedule->job(new PruneAgentHarnessRecords)
                ->dailyAt('03:10')->name('agent-harness:prune')->withoutOverlapping();
        });
    }
}
