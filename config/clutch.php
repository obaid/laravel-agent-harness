<?php

declare(strict_types=1);

use Clutch\Laravel\Drivers\LaravelAi\LaravelAiDriver;
use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Workflows\WorkflowDriver;

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

    'default_driver' => env('CLUTCH_DRIVER', 'laravel-ai'),

    'drivers' => [
        'laravel-ai' => [
            'driver' => LaravelAiDriver::class,

            // Optional overrides applied to every session using this driver.
            'provider' => env('CLUTCH_PROVIDER'),
            'model' => env('CLUTCH_MODEL'),
            'timeout' => env('CLUTCH_TIMEOUT', 120),
        ],

        // Workflows are a runtime like any other, which is what gives them
        // leases, budgets, cancellation and recovery for free.
        'workflow' => [
            'driver' => WorkflowDriver::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflows
    |--------------------------------------------------------------------------
    |
    | A workflow is a finite job whose control flow is yours: ordinary PHP that
    | calls agents where judgement is needed. Steps are remembered, so a resume
    | after a pause, a lost worker or a deploy skips work that already happened.
    |
    */

    'workflows' => [
        // Whether steps() may run its work concurrently. Uses Laravel's own
        // concurrency driver, so `sync` in tests behaves predictably.
        'concurrent_steps' => env('CLUTCH_CONCURRENT_STEPS', true),

        // Discard a workflow's staged scratch once the session is destroyed.
        // Artifacts are recorded separately and are never touched by this.
        'discard_workspace' => env('CLUTCH_DISCARD_WORKFLOW_WORKSPACE', true),
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
        'connection' => env('CLUTCH_QUEUE_CONNECTION'),
        'queue' => env('CLUTCH_QUEUE', 'agents'),
        'timeout' => (int) env('CLUTCH_QUEUE_TIMEOUT', 900),
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
        'expires_after' => env('CLUTCH_APPROVAL_TTL'),
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
        'broadcast' => env('CLUTCH_BROADCAST', true),

        // Text and reasoning deltas are reconstructable from the terminal run
        // output. Turn this off to keep a busy event table small.
        'persist_deltas' => env('CLUTCH_PERSIST_DELTAS', true),

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
    | Skills
    |--------------------------------------------------------------------------
    |
    | Reusable instruction bundles an agent can reach for when a task calls for
    | one. The model sees every skill's name and description, and pulls in the
    | body of the one it needs, so a large library costs a line each rather
    | than its full weight on every turn.
    |
    | Point "path" at a directory of skill folders, each holding a SKILL.md,
    | or register them inline below.
    |
    */

    'skills' => [
        'path' => env('CLUTCH_SKILLS_PATH'),

        'registered' => [
            // [
            //     'name' => 'careful-refactors',
            //     'description' => 'Make small, low-risk code changes.',
            //     'content' => 'Prefer minimal diffs. Preserve public APIs...',
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool output spill
    |--------------------------------------------------------------------------
    |
    | A tool that returns a very large result poisons every later step: the
    | model pays for it on each turn and the context fills with text nobody
    | reads. Oversized results are written to an artifact instead, and the model
    | is handed a bounded preview plus the artifact id.
    |
    */

    'spill' => [
        'enabled' => env('CLUTCH_SPILL', true),
        'threshold_bytes' => (int) env('CLUTCH_SPILL_THRESHOLD', 8192),
        'preview_bytes' => (int) env('CLUTCH_SPILL_PREVIEW', 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Loop guards
    |--------------------------------------------------------------------------
    |
    | Budgets catch a run that is expensive. They do not catch one that is cheap
    | and useless, like an agent calling the same tool with the same arguments
    | forty times. These guards notice that shape and, past a point, refuse.
    |
    | Deadlines bound a single tool call, which a run-level duration budget
    | cannot do: it only notices an overrun once the tool returns, which is no
    | help when the tool is the thing that hung.
    |
    */

    'guards' => [
        'enabled' => env('CLUTCH_GUARDS', true),
        'remind_after_repeats' => 3,
        'block_after_repeats' => 8,

        'tool_timeout_seconds' => env('CLUTCH_TOOL_TIMEOUT'),

        // 'search_web' => 30,
        'tool_timeouts' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Compaction
    |--------------------------------------------------------------------------
    |
    | A long session accumulates conversation until every turn pays for the
    | whole history. Compaction summarizes the middle of it, keeping the
    | earliest turns (which hold the task) and the most recent (which hold the
    | state). The summary is produced by Laravel AI's SummarizeAgent, which uses
    | the cheapest model available.
    |
    | Off by default: rewriting a conversation is not something to do to an
    | application without it asking.
    |
    */

    'compaction' => [
        'enabled' => env('CLUTCH_COMPACTION', false),
        'trigger_at_fraction' => 0.7,
        'keep_first' => 2,
        'keep_recent' => 8,
        'summary_sentences' => 6,
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
        'disk' => env('CLUTCH_ARTIFACT_DISK', 'local'),
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
        'store' => env('CLUTCH_LEASE_STORE'),
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
    | Turn limits
    |--------------------------------------------------------------------------
    |
    | How much work one slice of a turn may do before the driver hands it back
    | and the run is re-queued to continue. This is not a budget: a budget ends
    | a run, these limits end a slice and leave the run alive.
    |
    | Size the wall-clock limit below your queue worker's timeout so a long run
    | parks itself deliberately instead of being killed part-way through.
    |
    | Both are null by default, meaning a turn runs to completion. Only drivers
    | declaring the "time_slicing" capability accept them; the bundled
    | laravel-ai driver does not, because Laravel AI cannot resume a turn it
    | abandoned mid-flight.
    |
    */

    'limits' => [
        'max_steps_per_slice' => env('CLUTCH_MAX_STEPS_PER_SLICE'),
        'max_seconds_per_slice' => env('CLUTCH_MAX_SECONDS_PER_SLICE'),
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
        'enabled' => env('CLUTCH_ROUTES', true),
        'prefix' => 'api/clutch',
        'middleware' => ['api', 'auth'],
    ],

];
