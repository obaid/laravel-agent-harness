<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tests;

use Clutch\Laravel\ClutchServiceProvider;
use Clutch\Laravel\Runtime\RunContext;
use Clutch\Laravel\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RunContext::flush();
    }

    protected function tearDown(): void
    {
        RunContext::flush();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            ClutchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // SQLite in memory by default for speed. CI also runs the whole suite
        // against PostgreSQL and Redis, because row locking, concurrent event
        // sequences, and lease expiry cannot be proven on SQLite.
        $connection = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection === 'pgsql'
            ? [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'harness'),
                'username' => env('DB_USERNAME', 'harness'),
                'password' => env('DB_PASSWORD', 'secret'),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]
            : [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

        $app['config']->set('cache.default', env('CACHE_STORE', 'array'));
        $app['config']->set('database.redis.default.host', env('REDIS_HOST', '127.0.0.1'));
        $app['config']->set('database.redis.cache.host', env('REDIS_HOST', '127.0.0.1'));
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('clutch.events.broadcast', false);
        $app['config']->set('clutch.default_driver', 'fake');

        // mergeConfigFrom is a shallow merge, so the whole drivers map has to
        // be declared here rather than only the key being added.
        $app['config']->set('clutch.drivers', [
            'fake' => [
                'driver' => \Clutch\Laravel\Drivers\FakeDriver::class,
            ],
            'laravel-ai' => [
                'driver' => \Clutch\Laravel\Drivers\LaravelAi\LaravelAiDriver::class,
            ],
        ]);

        // Titles are generated with a real provider call; tests never want one.
        $app['config']->set('ai.conversations.generate_title', false);

        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Laravel AI's conversation tables. The harness stores session context
        // there, so the suite exercises the real thing rather than a stand-in.
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/ai/database/migrations');
    }

    /**
     * Create a persisted user for participant scoping.
     */
    protected function user(string $email = 'taylor@example.com'): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => $email,
        ]);
    }
}
