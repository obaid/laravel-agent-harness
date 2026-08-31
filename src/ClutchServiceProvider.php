<?php

declare(strict_types=1);

namespace Clutch\Laravel;

use Clutch\Laravel\Approvals\ApprovalBroker;
use Clutch\Laravel\Artifacts\ArtifactManager;
use Clutch\Laravel\Budgets\BudgetManager;
use Clutch\Laravel\Budgets\CostEstimator;
use Clutch\Laravel\Checkpoints\CheckpointStore;
use Clutch\Laravel\Compaction\CompactionPolicy;
use Clutch\Laravel\Compaction\Compactor;
use Clutch\Laravel\Console\CancelRunCommand;
use Clutch\Laravel\Console\EventsCommand;
use Clutch\Laravel\Console\MakeClutchAgentCommand;
use Clutch\Laravel\Console\MakeClutchWorkflowCommand;
use Clutch\Laravel\Console\PruneCommand;
use Clutch\Laravel\Console\ReapCommand;
use Clutch\Laravel\Console\RetryRunCommand;
use Clutch\Laravel\Console\RunCommand;
use Clutch\Laravel\Console\SessionsCommand;
use Clutch\Laravel\Contracts\SandboxProvider;
use Clutch\Laravel\Drivers\LaravelAi\EventTranslator;
use Clutch\Laravel\Guards\LoopGuard;
use Clutch\Laravel\Guards\ToolDeadline;
use Clutch\Laravel\Jobs\ExpireApprovals;
use Clutch\Laravel\Jobs\PruneClutchRecords;
use Clutch\Laravel\Jobs\ReapAbandonedRuns;
use Clutch\Laravel\Leases\LeaseManager;
use Clutch\Laravel\Policies\PolicyAwareTools;
use Clutch\Laravel\Policies\PolicyEngine;
use Clutch\Laravel\Runtime\DriverRegistry;
use Clutch\Laravel\Runtime\EventStore;
use Clutch\Laravel\Runtime\Redactor;
use Clutch\Laravel\Runtime\RunCoordinator;
use Clutch\Laravel\Sandbox\NullSandboxProvider;
use Clutch\Laravel\Skills\SkillRegistry;
use Clutch\Laravel\Streaming\EventStreamResponse;
use Clutch\Laravel\Tools\SpillPolicy;
use Clutch\Laravel\Tools\ToolExecutionLedger;
use Clutch\Laravel\ValueObjects\RunBudget;
use Clutch\Laravel\Workflows\WorkflowAgentCaller;
use Clutch\Laravel\Workflows\WorkflowDriver;
use Clutch\Laravel\Workflows\WorkflowRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ClutchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/clutch.php', 'clutch');

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
            sensitiveKeys: (array) $app['config']->get('clutch.events.redact', []),
            toolSerializers: (array) $app['config']->get('clutch.events.serializers', []),
        ));

        $this->app->singleton(CostEstimator::class, fn ($app): CostEstimator => new CostEstimator(
            (array) $app['config']->get('clutch.pricing', []),
        ));

        $this->app->singleton(BudgetManager::class, fn ($app): BudgetManager => new BudgetManager(
            RunBudget::fromArray((array) $app['config']->get('clutch.budgets', [])),
        ));

        $this->app->singleton(LeaseManager::class, fn ($app): LeaseManager => new LeaseManager(
            cache: $app['cache'],
            store: $app['config']->get('clutch.leases.store'),
            ttlSeconds: (int) $app['config']->get('clutch.leases.ttl_seconds', 60),
            heartbeatSeconds: (int) $app['config']->get('clutch.leases.heartbeat_seconds', 15),
        ));

        $this->app->singleton(PolicyEngine::class, fn ($app): PolicyEngine => new PolicyEngine(
            sensitivityMap: (array) $app['config']->get('clutch.permissions.tools', []),
            alwaysAllow: (array) $app['config']->get('clutch.permissions.always_allow', []),
        ));

        $this->app->singleton(PolicyAwareTools::class);

        $this->app->singleton(SkillRegistry::class, fn ($app): SkillRegistry => tap(
            new SkillRegistry((array) $app['config']->get('clutch.skills.registered', [])),
            function (SkillRegistry $registry) use ($app): void {
                $path = $app['config']->get('clutch.skills.path');

                if (is_string($path) && is_dir($path)) {
                    $registry->discover($path);
                }
            },
        ));

        $this->app->singleton(SpillPolicy::class, fn ($app): SpillPolicy => new SpillPolicy(
            thresholdBytes: (int) $app['config']->get('clutch.spill.threshold_bytes', 8192),
            previewBytes: (int) $app['config']->get('clutch.spill.preview_bytes', 1024),
            enabled: (bool) $app['config']->get('clutch.spill.enabled', true),
        ));

        // Not shared: a guard counts repeats within one run, so a singleton
        // would carry one run's history into the next.
        $this->app->bind(LoopGuard::class, fn ($app): LoopGuard => new LoopGuard(
            remindAfter: (int) $app['config']->get('clutch.guards.remind_after_repeats', 3),
            blockAfter: (int) $app['config']->get('clutch.guards.block_after_repeats', 8),
            enabled: (bool) $app['config']->get('clutch.guards.enabled', true),
        ));

        $this->app->singleton(ToolDeadline::class, fn ($app): ToolDeadline => new ToolDeadline(
            defaultSeconds: $app['config']->get('clutch.guards.tool_timeout_seconds'),
            perTool: (array) $app['config']->get('clutch.guards.tool_timeouts', []),
        ));

        $this->app->singleton(CompactionPolicy::class, fn ($app): CompactionPolicy => new CompactionPolicy(
            triggerAtFraction: (float) $app['config']->get('clutch.compaction.trigger_at_fraction', 0.7),
            keepFirst: (int) $app['config']->get('clutch.compaction.keep_first', 2),
            keepRecent: (int) $app['config']->get('clutch.compaction.keep_recent', 8),
            summarySentences: (int) $app['config']->get('clutch.compaction.summary_sentences', 6),
            enabled: (bool) $app['config']->get('clutch.compaction.enabled', false),
        ));

        $this->app->singleton(Compactor::class, fn ($app): Compactor => new Compactor(
            policy: $app->make(CompactionPolicy::class),
            conversations: $app->make(\Laravel\Ai\Contracts\ConversationStore::class),
            events: $app->make(EventStore::class),
            logger: $app['log'],
        ));
        $this->app->singleton(CheckpointStore::class);

        $this->app->singleton(ToolExecutionLedger::class, fn ($app): ToolExecutionLedger => new ToolExecutionLedger(
            spill: $app->make(SpillPolicy::class),
            guard: $app->make(LoopGuard::class),
            deadline: $app->make(ToolDeadline::class),
        ));
        $this->app->singleton(EventTranslator::class);

        $this->app->singleton(SandboxProvider::class, NullSandboxProvider::class);

        $this->app->singleton(EventStore::class, fn ($app): EventStore => new EventStore(
            connection: $app['db']->connection(),
            redactor: $app->make(Redactor::class),
            persistDeltas: (bool) $app['config']->get('clutch.events.persist_deltas', true),
        ));

        $this->app->singleton(ApprovalBroker::class, fn ($app): ApprovalBroker => new ApprovalBroker(
            connection: $app['db']->connection(),
            events: $app->make(EventStore::class),
            expiresAfterSeconds: $app['config']->get('clutch.approvals.expires_after') !== null
                ? (int) $app['config']->get('clutch.approvals.expires_after')
                : null,
        ));

        $this->app->singleton(ArtifactManager::class);

        $this->app->singleton(WorkflowAgentCaller::class);
        $this->app->singleton(WorkflowRunner::class);

        $this->app->singleton(EventStreamResponse::class, fn ($app): EventStreamResponse => new EventStreamResponse(
            events: $app->make(EventStore::class),
            pollIntervalMicroseconds: (int) $app['config']->get('clutch.streaming.poll_interval_ms', 250) * 1000,
            keepAliveSeconds: (int) $app['config']->get('clutch.streaming.keep_alive_seconds', 15),
            maxDurationSeconds: (int) $app['config']->get('clutch.streaming.max_duration_seconds', 300),
        ));
    }

    protected function registerRuntime(): void
    {
        $this->app->singleton(DriverRegistry::class, fn ($app): DriverRegistry => new DriverRegistry(
            container: $app,
            config: [
                // Workflows are a built-in runtime rather than a provider the
                // user chooses between, so they are registered here rather
                // than depending on a published config file. An application
                // that published `clutch.php` before workflows existed would
                // otherwise upgrade into a driver that does not resolve.
                'workflow' => ['driver' => WorkflowDriver::class],
                ...(array) $app['config']->get('clutch.drivers', []),
            ],
            default: (string) $app['config']->get('clutch.default_driver', 'laravel-ai'),
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

        $this->app->singleton(ClutchManager::class, fn ($app): ClutchManager => new ClutchManager(
            container: $app,
            coordinator: $app->make(RunCoordinator::class),
            drivers: $app->make(DriverRegistry::class),
        ));

        $this->app->alias(ClutchManager::class, 'clutch');
    }

    protected function publishesResources(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/clutch.php' => config_path('clutch.php'),
        ], ['clutch', 'clutch-config']);

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['clutch', 'clutch-migrations']);
    }

    protected function registerRoutes(): void
    {
        if ($this->app['config']->get('clutch.routes.enabled', true) !== true) {
            return;
        }

        Route::group([
            'prefix' => $this->app['config']->get('clutch.routes.prefix', 'api/clutch'),
            'middleware' => $this->app['config']->get('clutch.routes.middleware', ['api']),
            'as' => 'clutch.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/clutch.php');
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
        if ($this->app['config']->get('clutch.events.broadcast', true) !== true) {
            return;
        }

        if (! $this->app->bound(\Illuminate\Contracts\Broadcasting\Factory::class)) {
            return;
        }

        Broadcast::channel('clutch.run.{runId}', function ($user, string $runId): bool {
            $run = Models\Run::query()->with('session')->find($runId);

            return $run?->session?->belongsToParticipant($user) ?? false;
        });

        Broadcast::channel('clutch.session.{sessionId}', function ($user, string $sessionId): bool {
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
            MakeClutchAgentCommand::class,
            MakeClutchWorkflowCommand::class,
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
                staleAfterSeconds: (int) $config->get('clutch.recovery.stale_after_seconds', 1200),
                retry: (bool) $config->get('clutch.recovery.retry_abandoned', true),
            ))->everyFiveMinutes()->name('agent-clutch:reap')->withoutOverlapping();

            if ($config->get('clutch.approvals.expires_after') !== null) {
                $schedule->job(new ExpireApprovals)
                    ->everyFiveMinutes()->name('agent-clutch:expire-approvals')->withoutOverlapping();
            }

            $schedule->job(new PruneClutchRecords)
                ->dailyAt('03:10')->name('agent-clutch:prune')->withoutOverlapping();
        });
    }
}
