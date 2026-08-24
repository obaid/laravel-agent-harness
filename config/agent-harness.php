<?php

declare(strict_types=1);

use AgentHarness\Laravel\Drivers\LaravelAiDriver;
use AgentHarness\Laravel\Enums\PermissionMode;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | The runtime used when a session does not name one. The bundled
    | "laravel-ai" driver runs ordinary Laravel AI agents inside your own
    | application workers.
    |
    */

    'default_driver' => env('AGENT_HARNESS_DRIVER', 'laravel-ai'),

    'drivers' => [
        'laravel-ai' => [
            'driver' => LaravelAiDriver::class,

            // Optional overrides applied to every session using this driver.
            'provider' => env('AGENT_HARNESS_PROVIDER'),
            'model' => env('AGENT_HARNESS_MODEL'),
            'timeout' => env('AGENT_HARNESS_TIMEOUT', 120),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where queued runs are dispatched. A session may override both values.
    | Give agents their own queue: a long run should never block ordinary jobs.
    |
    */

    'queue' => [
        'connection' => env('AGENT_HARNESS_QUEUE_CONNECTION'),
        'queue' => env('AGENT_HARNESS_QUEUE', 'agents'),
        'timeout' => (int) env('AGENT_HARNESS_QUEUE_TIMEOUT', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    |
    | The default approval policy for new sessions, and the classification the
    | policy engine applies to individual tools. Tools you do not classify are
    | treated as sensitive, because guessing in the safe direction is the only
    | guess worth making.
    |
    */

    'permissions' => [
        'default' => PermissionMode::ApproveSensitive->value,

        // 'app.tools.publish_article' => 'irreversible',
        'tools' => [
            // 'search_web' => 'read_only',
            // 'draft_email' => 'reversible',
            // 'send_email' => 'irreversible',
        ],

        // Tool names permitted even under the deny-by-default mode.
        'always_allow' => [],
    ],

    'approvals' => [
        // Seconds before an undecided approval expires. Null keeps it open
        // forever; an expired approval reads as a rejection to the agent.
        'expires_after' => env('AGENT_HARNESS_APPROVAL_TTL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Redaction runs before persistence, not before display, so a configured
    | key never enters the database at all. Add anything your tools handle.
    |
    */

    'events' => [
        'broadcast' => env('AGENT_HARNESS_BROADCAST', true),

        // Text and reasoning deltas are reconstructable from the terminal run
        // output. Turn this off to keep a busy event table small.
        'persist_deltas' => env('AGENT_HARNESS_PERSIST_DELTAS', true),

        'redact' => [
            'authorization', 'api_key', 'apikey', 'token', 'password', 'secret',
            'access_token', 'refresh_token', 'client_secret', 'private_key',
            'credit_card', 'card_number', 'cvv', 'ssn',
        ],

        // Per-tool serializers that keep only approved fields in the history.
        // 'send_email' => App\Ai\Serializers\EmailEventSerializer::class,
        'serializers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming
    |--------------------------------------------------------------------------
    */

    'streaming' => [
        'poll_interval_ms' => 250,
        'keep_alive_seconds' => 15,

        // How long one SSE connection is held before the client is asked to
        // reconnect with its cursor. Keeps workers from being pinned forever.
        'max_duration_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Artifacts
    |--------------------------------------------------------------------------
    |
    | Artifact bytes live on a filesystem disk; only metadata is stored in the
    | database.
    |
    */

    'artifacts' => [
        'disk' => env('AGENT_HARNESS_ARTIFACT_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Leases
    |--------------------------------------------------------------------------
    |
    | One coordinator per session. Redis is strongly preferred; on stores
    | without atomic locks the database version columns remain the final
    | correctness check.
    |
    */

    'leases' => [
        'store' => env('AGENT_HARNESS_LEASE_STORE'),
        'ttl_seconds' => 60,
        'heartbeat_seconds' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Budgets
    |--------------------------------------------------------------------------
    |
    | Hard limits applied to every run, before any session or run budget. A
    | session may only make these more restrictive, never less.
    |
    */

    'budgets' => [
        'max_steps' => 50,
        'max_tool_calls' => 100,
        'max_tokens' => 250_000,
        'max_cost_usd' => null,
        'max_duration_seconds' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | USD per million tokens, keyed by "provider:model" or by bare model name.
    | Only priced models contribute to a max_cost_usd budget; an unpriced model
    | contributes nothing rather than guessing.
    |
    */

    'pricing' => [
        // 'anthropic:claude-sonnet-4-5' => ['input' => 3.00, 'output' => 15.00],
        // 'openai:gpt-5' => ['input' => 1.25, 'output' => 10.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Days to keep each record type. Null disables pruning for that type.
    | Pruning never removes data an active or resumable run still needs.
    |
    */

    'retention' => [
        'events' => 90,
        'checkpoints' => 30,
        'tool_executions' => 90,
        'artifacts' => 365,
        'run_payloads' => null,
        'sessions' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery
    |--------------------------------------------------------------------------
    |
    | A run whose worker vanished is detected once its heartbeat goes stale and
    | nobody holds its session lease.
    |
    */

    'recovery' => [
        'stale_after_seconds' => 300,
        'retry_abandoned' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The package's HTTP endpoints. Disable them entirely and build your own if
    | you would rather own the surface.
    |
    */

    'routes' => [
        'enabled' => env('AGENT_HARNESS_ROUTES', true),
        'prefix' => 'api/agent-harness',
        'middleware' => ['api', 'auth'],
    ],

];
