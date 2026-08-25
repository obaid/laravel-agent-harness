# Architecture

This document defines the internal architecture required to fulfill the public contract in the project README. It is normative for v1 unless a later architecture decision record explicitly replaces a section.

## 1. Goals

Laravel Clutch must:

1. Make Laravel AI agents durable across HTTP requests and completed turns.
2. Provide a stable runtime contract independent of any one agent implementation.
3. Persist an ordered, replayable account of each run.
4. Support background execution, streaming, approvals, cancellation, artifacts, budgets, and retries.
5. Preserve Laravel conventions: queues, events, broadcasting, policies, storage, service container, and testing fakes.
6. Make unsafe or unsupported behavior explicit.
7. Allow future runtime and sandbox drivers without placing those concerns in the core domain.

## 2. Non-goals for v1

The first release does not attempt to:

- Implement its own model provider SDK
- Replace Laravel AI conversations or tools
- Become a general durable-workflow engine
- Guarantee continuation from the middle of an arbitrary HTTP provider request
- Provide a built-in Linux sandbox
- Run multiple concurrent turns in the same session
- Expose hidden model reasoning when the provider does not supply it
- Automatically make non-idempotent external actions safe to retry

## 3. System boundary

The harness owns:

- Session and run lifecycle
- Driver selection and capability validation
- Queue coordination and execution leases
- Normalized event recording and replay
- Approval persistence and routing
- Budget enforcement
- Cancellation state
- Checkpoints
- Artifact metadata
- Tool idempotency records
- Audit and redaction policy

Laravel AI owns:

- Provider communication
- Agent instructions
- Conversation messages
- Model/tool iteration
- Laravel AI tools and provider tools
- Structured output validation
- Provider-specific options
- Laravel AI usage objects and raw streaming events

Applications own:

- Agent and tool implementations
- Authorization policy
- Tenant boundaries
- Business-domain records
- External credentials and connected accounts
- The frontend experience
- Artifact access policy

Optional drivers own:

- Native runtime startup and shutdown
- Runtime-specific conversation state
- Runtime event translation
- Runtime-specific tool and approval transport
- Runtime-specific checkpoint payloads

Optional sandbox providers own:

- Filesystem and process isolation
- Workspace provisioning
- Network policy enforcement
- Snapshotting and destruction

## 4. Core components

### `ClutchManager`

Public entry point used by the facade. Creates and loads sessions and runs, resolves authorization hooks, and delegates lifecycle work to the coordinator.

### `RunCoordinator`

The single orchestration authority for state transitions. It validates the requested operation, obtains the session lease, persists transitions, invokes the driver, records events, and releases the lease.

No controller, queue job, event listener, or driver may update lifecycle status directly.

### `DriverRegistry`

Resolves configured drivers by name and validates their declared capabilities.

### `EventStore`

Appends events transactionally, assigns sequences, supports cursor replay, applies redaction, and schedules after-commit broadcasting.

### `ApprovalBroker`

Creates approval records, resolves decisions idempotently, validates the actor, and wakes a paused run.

### `CheckpointStore`

Persists versioned, encrypted driver checkpoint envelopes. Checkpoint contents are opaque to the coordinator beyond common metadata.

### `LeaseManager`

Guarantees one active coordinator for a session. Redis is preferred for heartbeat leases; a database lock is the correctness fallback.

### `BudgetManager`

Combines configured, session, and run budgets; records consumption; and blocks a new step or tool when a hard limit would be exceeded.

### `ArtifactManager`

Persists artifact metadata and validates storage references. Artifact bytes remain on a Laravel filesystem disk.

### `ToolExecutionLedger`

Stores tool-call identity, idempotency keys, arguments digests, status, and result references. It prevents duplicate execution where the tool supplies an idempotency contract.

### `PolicyEngine`

Combines harness permission mode, tool sensitivity, Laravel policies, tenant scope, and application callbacks. The most restrictive result wins.

## 5. Runtime contracts

### Harness driver

```php
interface ClutchDriver
{
    public function name(): string;

    public function capabilities(): DriverCapabilities;

    public function start(StartSession $command): DriverSession;

    public function runTurn(
        DriverSession $session,
        TurnInput $input,
        DriverEventSink $events,
        CancellationSignal $cancellation,
    ): TurnResult;

    public function continueTurn(
        DriverSession $session,
        Continuation $continuation,
        DriverEventSink $events,
        CancellationSignal $cancellation,
    ): TurnResult;

    public function checkpoint(DriverSession $session): DriverCheckpoint;

    public function restore(DriverCheckpoint $checkpoint): DriverSession;

    public function stop(DriverSession $session): DriverCheckpoint;

    public function destroy(DriverSession $session): void;
}
```

Drivers may throw `CapabilityUnsupported` for optional methods, but only after capability declaration has prevented ordinary callers from reaching that path. A mismatch between declared and actual capability is a driver bug.

A driver returns exactly one outcome from a turn: `completed`, `awaiting_approval`,
`cancelled`, `failed`, or `budget_exceeded`. The last exists so a driver that
enforces a limit mid-turn can say so precisely, rather than reporting a generic
failure the coordinator would have to interpret.

### Driver capabilities

```php
final readonly class DriverCapabilities
{
    public function __construct(
        public bool $streaming,
        public bool $hostTools,
        public bool $nativeTools,
        public bool $approvals,
        public bool $structuredOutput,
        public bool $sessionResume,
        public bool $inFlightContinuation,
        public bool $manualCompaction,
        public bool $sandboxRequired,
        public bool $workspaceRequired,
    ) {}
}
```

### Sandbox provider

```php
interface SandboxProvider
{
    public function create(SandboxConfig $config): SandboxSession;

    public function restore(SandboxCheckpoint $checkpoint): SandboxSession;

    public function checkpoint(SandboxSession $session): SandboxCheckpoint;

    public function stop(SandboxSession $session): SandboxCheckpoint;

    public function destroy(SandboxSession $session): void;
}
```

The core package includes a `NullSandboxProvider` used by host-resident application agents. Cloud sandbox integrations belong in separate packages.

## 6. Domain model

### Session

A session is the unit of sequential context continuity.

Required fields:

- `id`
- `tenant_type`, `tenant_id` when tenant scoping is enabled
- `participant_type`, `participant_id`
- `agent_class` or `runtime_name`
- `driver`
- `name`
- `status`
- `permission_mode`
- `conversation_id`, nullable
- `workspace_id`, nullable
- `configuration`, encrypted JSON
- `budget`, JSON
- `active_run_id`, nullable
- `version`, optimistic-lock integer
- timestamps and soft-delete metadata

### Run

A run is one attempt to process one new input.

Required fields:

- `id`
- `session_id`
- `attempt`
- `idempotency_key`, nullable
- `status`
- `input_type`
- `input`, encrypted JSON
- `output_text`, nullable
- `structured_output`, nullable JSON
- `usage`, nullable JSON
- `cost_usd`, nullable decimal
- `last_event_sequence`
- `last_checkpoint_id`, nullable
- `cancellation_requested_at`, nullable
- `started_at`, `finished_at`
- normalized error fields
- timestamps

### Event

Events are append-only. They are never updated or deleted individually before retention pruning.

Required fields:

- sortable `id`
- `session_id`
- `run_id`
- `sequence`
- `type`
- `payload`, JSON
- `occurred_at`

Unique constraint: `(run_id, sequence)`.

### Approval

Required fields:

- `id`
- `session_id`
- `run_id`
- `tool_call_id`
- `tool_name`
- `arguments`, encrypted JSON
- `reason`, nullable
- `status`
- `requested_at`
- `expires_at`, nullable
- `resolved_at`, nullable
- `resolved_by_type`, `resolved_by_id`, nullable
- `decision_reason`, nullable
- `version`

Unique constraint: `(run_id, tool_call_id)` unless a driver explicitly supports multiple approval phases for one call.

### Checkpoint

Required fields:

- `id`
- `session_id`
- `run_id`, nullable between turns
- `driver`
- `driver_version`
- `reason`
- `payload`, encrypted binary or JSON
- `payload_digest`
- `event_sequence`
- `portable`, boolean
- `created_at`

Checkpoint payloads must include a schema version. Secrets must not be included.

### Artifact

Required fields:

- `id`
- `session_id`
- `run_id`
- `name`
- `description`, nullable
- `kind`
- `mime_type`
- `disk`
- `path`
- `size_bytes`, nullable
- `sha256`, nullable
- `metadata`, JSON
- timestamps

## 7. State machines

### Session states

```text
creating -> ready -> running -> ready
                    |   |
                    |   -> awaiting_approval -> running
                    |
                    -> stopping -> stopped -> ready

creating/running/stopping -> failed
ready/stopped/failed -> destroyed
```

Canonical values:

- `creating`
- `ready`
- `running`
- `awaiting_approval`
- `stopping`
- `stopped`
- `failed`
- `destroyed`

### Run states

```text
created -> queued -> running -> completed
                      |
                      -> awaiting_approval -> queued
                      -> cancelling -> cancelled
                      -> failed
                      -> budget_exceeded
```

Canonical values:

- `created`
- `queued`
- `running`
- `awaiting_approval`
- `cancelling`
- `completed`
- `failed`
- `cancelled`
- `budget_exceeded`

Terminal states are immutable. Retrying creates a new run attempt linked to the original run; it does not reopen the terminal record.

## 8. Transaction rules

1. State and its corresponding lifecycle event are committed in the same database transaction.
2. `last_event_sequence` is incremented while holding the run row lock.
3. Broadcasts happen only after commit.
4. A terminal result, terminal state, final usage, and terminal event commit together.
5. An approval decision and `approval.resolved` event commit together.
6. `active_run_id` is cleared in the same transaction that makes a run terminal.
7. No external model or tool call occurs while holding a database transaction open.

## 9. Event protocol

### Envelope

```json
{
  "id": "evt_01J...",
  "session_id": "ses_01J...",
  "run_id": "run_01J...",
  "sequence": 42,
  "type": "step.completed",
  "occurred_at": "2026-08-24T21:15:12.381Z",
  "payload": {}
}
```

### Ordering

Ordering is guaranteed only within one run. Cross-run ordering should use `occurred_at` and event ID but is not a correctness guarantee.

### Replay and live handoff

The stream endpoint performs:

1. Authorize access to the run.
2. Read events after the requested cursor.
3. Subscribe to the live run channel.
4. Read again after the last replayed cursor to close the subscription race.
5. Forward live events, deduplicating by sequence.
6. Close after a terminal event unless the client requests a persistent session stream.

### Delivery

Storage is durable and ordered. Network delivery is at least once. Clients deduplicate by run ID and sequence.

### Redaction

Redaction occurs before persistence, not only before display. Raw secrets must never enter an event object. Configured sensitive keys are recursively replaced with `[REDACTED]`.

Tool arguments may contain business-sensitive values. Applications may configure tool-specific event serializers that retain only approved fields.

## 10. Execution lifecycle

### Creating a session

1. Validate agent/runtime and driver compatibility.
2. Authorize session creation.
3. Persist `creating` session.
4. Provision optional workspace/sandbox.
5. Start the driver.
6. Store its between-turn checkpoint.
7. Transition session to `ready`.
8. Destroy provisioned resources and mark `failed` if startup fails.

### Starting a run

1. Validate input and requested options.
2. Resolve the effective budget and permissions.
3. Atomically verify no active run exists.
4. Create run and initial events.
5. Assign `active_run_id`.
6. Dispatch `ExecuteAgentRun` after commit for queued execution, or enter the coordinator for synchronous execution.

### Executing a run

1. Obtain session lease.
2. Reload session and run with fresh state.
3. Refuse terminal, stale, or superseded work.
4. Transition to `running`.
5. Restore the driver checkpoint.
6. Invoke the driver with event and cancellation sinks.
7. Normalize and record events incrementally.
8. Persist checkpoints at safe boundaries.
9. Complete, pause, cancel, exceed budget, or fail.
10. Release the lease in `finally`.

### Approval pause

1. Driver emits an approval request.
2. Coordinator persists approval and events.
3. A safe checkpoint is stored.
4. Run and session transition to `awaiting_approval`.
5. Worker exits normally.
6. Decision endpoint resolves the approval idempotently.
7. Run transitions to `queued` and `ContinueAgentRun` is dispatched after commit.
8. Driver restores and receives the decision continuation.

### Completed-turn continuation

The session's between-turn checkpoint and Laravel AI conversation ID are stored after every successful run. A later run restores them before sending only the new input.

## 11. Laravel AI driver

The default driver wraps a normal Laravel AI agent.

Responsibilities:

- Resolve the agent through Laravel's container
- Restore or initialize Laravel AI conversation context
- Apply provider, model, timeout, and structured-output options
- Translate Laravel AI stream events into harness events
- Translate pending approvals into harness approvals
- Capture Laravel AI usage and step metadata
- Store the resulting conversation ID
- Surface artifacts registered through `RunContext`
- Use Laravel AI fakes in package tests

### Continuation guarantee

The driver supports:

- New sessions
- New turns in an existing session
- Reconnection to a still-running queued turn through persisted event replay
- Approval pauses supported by Laravel AI conversation persistence
- Retry from the last safe model/tool boundary

It does not guarantee continuation from the middle of a provider HTTP request. If a worker dies during that request, the current step may be repeated. Side-effecting tools therefore require idempotency.

### Streaming

The driver consumes the Laravel AI event iterator. It records normalized events before broadcasting them. Direct HTTP streaming uses the same event recorder so synchronous and queued runs have identical history.

## 12. Queue and lease design

Primary jobs:

- `ExecuteAgentRun`
- `ContinueAgentRun`
- `CancelAgentRun`
- `StopAgentSession`
- `DestroyAgentSession`
- `PruneClutchRecords`

Every execution job contains only identifiers and an expected state/version. It reloads all mutable data.

The lease key is `clutch:session:{session-id}`. The worker renews it on a heartbeat. The database `version` and `active_run_id` fields remain the final correctness checks if Redis is unavailable or a lease expires incorrectly.

Leases require a cache store implementing `LockProvider`. A store without atomic
locks is refused with `LeaseUnavailable` rather than falling back to a
read-then-write approximation: a racy lease that silently permits two
coordinators for one session is precisely the failure this component exists to
prevent. Every cache store Laravel ships except `null` qualifies.

Liveness is tested by attempting to take the lease rather than by reading the
key. A cache lock does not live under a key `has()` can observe, so a direct
read reports "free" for a lease that is very much held. Anything acting on the
answer holds the lease itself rather than checking and then acting.

A job may be delivered more than once. Duplicate delivery must exit safely after observing that another worker holds the lease or the expected state/version no longer matches.

## 13. Failure and retry semantics

### Failure categories

- `validation_error`
- `authorization_error`
- `provider_error`
- `rate_limited`
- `tool_error`
- `driver_error`
- `checkpoint_error`
- `sandbox_error`
- `budget_exceeded`
- `cancelled`
- `worker_lost`
- `unknown`

Errors stored for users must be normalized and safe. Provider response bodies, credentials, stack traces, and tool secrets belong only in protected application logs.

### Retry policy

- Validation and authorization failures are not retried.
- Rate limits and transient provider failures may retry with exponential backoff and jitter.
- Non-idempotent tool failures do not retry automatically.
- A lost worker may resume from the last checkpoint.
- Manual retry creates a new attempt linked to the failed run.
- Budgets apply across attempts by default; applications may explicitly reset them.

## 14. Tool safety

Every tool invocation receives:

- Session ID
- Run ID
- Tool-call ID
- Participant and tenant context
- Effective permission mode
- Idempotency key when available
- Cancellation signal
- Artifact registrar
- Structured logger with redaction

Tools should be classified as:

- `read_only`
- `reversible`
- `sensitive`
- `irreversible`

Default policy:

- Read-only tools may run.
- Reversible tools follow application policy.
- Sensitive and irreversible tools require explicit permission or approval.
- Unknown tools are treated as sensitive.

## 15. Security and tenancy

1. Every public lookup must be tenant- and participant-scoped.
2. Sequential public IDs must not be used.
3. Session configuration, run input, approval arguments, and checkpoints are encrypted at rest.
4. Events are redacted before persistence.
5. Artifact downloads use Laravel authorization and temporary URLs when appropriate.
6. Drivers receive only the credentials required for the active turn.
7. Secrets are resolved immediately before use and are never checkpointed.
8. Sandbox network access is deny-by-default when a sandbox provider supports policy enforcement.
9. Tool authorization is evaluated immediately before execution, not only when the model requests the tool.
10. Approval actors and reasons are audit logged.

## 16. Observability

Minimum metrics:

- Sessions created, active, stopped, and failed
- Runs by state and driver
- Queue wait time
- Run duration
- Model and tool step duration
- Tool success and failure counts
- Approval wait duration
- Tokens and estimated cost
- Budget terminations
- Retries and duplicate-job exits
- Event recording and broadcast latency
- Lease contention and expiration

Trace correlation fields:

- `session_id`
- `run_id`
- `attempt`
- `driver`
- `provider`
- `model`
- `tool_call_id`

## 17. Pruning and retention

Retention is independently configurable for:

- Events
- Run inputs and outputs
- Checkpoints
- Artifacts
- Tool execution ledger
- Soft-deleted sessions

Pruning must not remove:

- Data needed by a non-terminal run
- The latest resumable checkpoint for a resumable session
- An unresolved approval
- Artifact metadata while the artifact remains user-accessible

Pruning runs in bounded batches and is safe to restart.

## 18. Extension packages

Potential extension packages:

- `clutch/sandbox-e2b`
- `clutch/sandbox-modal`
- `clutch/driver-codex`
- `clutch/driver-claude-code`
- `clutch/driver-opencode`
- `clutch/ui-react`
- `clutch/ui-livewire`

The core must not depend on any extension package.

## 19. Architectural invariants

These invariants require explicit automated tests:

1. A session has at most one active run.
2. A run has exactly one immutable terminal state.
3. Event sequences never decrease or repeat within a run.
4. Every lifecycle transition has a matching persisted event.
5. A terminal event is not visible without terminal state and result.
6. A resolved approval cannot execute twice.
7. Duplicate queue delivery cannot create duplicate active execution.
8. Cancellation prevents the next model/tool step from starting.
9. A checkpoint never contains configured secrets.
10. A driver cannot silently claim an unsupported capability.
11. A retry never reuses a terminal run record.
12. A user cannot retrieve another tenant's session through any package route.
13. A suspended turn resumes from its checkpoint without repeating finished work.
14. A tool call blocked by a guard never reaches the tool.


## 20. As-built component map

The components named in section 4 ship as these classes. Anything not listed is
an internal detail rather than an extension point.

| Component | Class |
| --- | --- |
| `ClutchManager` | `Clutch\Laravel\ClutchManager` |
| `RunCoordinator` | `Clutch\Laravel\Runtime\RunCoordinator` |
| `DriverRegistry` | `Clutch\Laravel\Runtime\DriverRegistry` |
| `EventStore` | `Clutch\Laravel\Runtime\EventStore` |
| `ApprovalBroker` | `Clutch\Laravel\Approvals\ApprovalBroker` |
| `CheckpointStore` | `Clutch\Laravel\Checkpoints\CheckpointStore` |
| `LeaseManager` | `Clutch\Laravel\Leases\LeaseManager` |
| `BudgetManager` | `Clutch\Laravel\Budgets\BudgetManager` |
| `ArtifactManager` | `Clutch\Laravel\Artifacts\ArtifactManager` |
| `ToolExecutionLedger` | `Clutch\Laravel\Tools\ToolExecutionLedger` |
| `PolicyEngine` | `Clutch\Laravel\Policies\PolicyEngine` |

Domain models map to tables as follows.

| Model | Table |
| --- | --- |
| `Models\Session` | `clutch_sessions` |
| `Models\Run` | `clutch_runs` |
| `Models\RunEvent` | `clutch_events` |
| `Models\Approval` | `clutch_approvals` |
| `Models\Checkpoint` | `clutch_checkpoints` |
| `Models\Artifact` | `clutch_artifacts` |
| `Models\ToolExecution` | `clutch_tool_executions` |

Two additions beyond section 4, both supporting behavior the contract already
required:

- `Runtime\Redactor` applies configured key redaction and per-tool
  serializers before persistence, and backs the checkpoint store's refusal to
  persist a secret.
- `Budgets\CostEstimator` converts token usage into an estimated dollar
  figure from a configured rate table, so `max_cost_usd` has something to
  measure against. An unpriced model contributes zero rather than a guess.

The invariants in section 19 each have a named test in
`tests/Feature/InvariantsTest.php`.
