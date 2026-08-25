# Development Guide

This guide turns the public README and architecture contract into an implementation plan. The README defines the desired developer experience. Architecture defines the required behavior. Code should be accepted only when it satisfies both.

## 1. Implementation philosophy

Build this package contract-first:

1. Write a failing public API or invariant test.
2. Implement the smallest domain behavior that passes it.
3. Add failure, concurrency, and authorization cases.
4. Integrate with Laravel AI only after the core state machine is proven with a fake driver.
5. Avoid runtime-specific behavior in core models and coordinators.

Do not start with sandbox integrations, a dashboard, or multiple drivers. The first useful release is a reliable Laravel AI driver with durable sessions, runs, events, approvals, and queued execution.

## 2. Target compatibility

- PHP: `^8.3`
- Laravel: `^12.0|^13.0`
- Laravel AI: `^0.11`
- Testbench: versions matching supported Laravel releases
- PostgreSQL: primary integration-test database
- Redis: queue, cache, lease, and broadcast integration tests

SQLite may be used for fast unit tests but is not sufficient for concurrency, JSON, locking, or production migration verification.

## 3. Repository structure

```text
laravel-clutch/
├── README.md
├── composer.json
├── config/
│   └── clutch.php
├── database/
│   ├── factories/
│   └── migrations/
├── docs/
│   ├── ARCHITECTURE.md
│   ├── DEVELOPMENT.md
│   └── adr/
├── routes/
│   └── clutch.php
├── src/
│   ├── ClutchServiceProvider.php
│   ├── ClutchManager.php
│   ├── Approvals/          ApprovalBroker
│   ├── Artifacts/          Artifact value object, manager, run-scoped registrar
│   ├── Budgets/            BudgetManager, CostEstimator
│   ├── Checkpoints/        CheckpointStore
│   ├── Console/            inspection, retry, cancel, reap, prune, generator
│   ├── Contracts/          ClutchDriver, DriverEventSink, IdempotentTool, …
│   ├── Data/               typed commands and results crossing the driver boundary
│   ├── Drivers/            FakeDriver, LaravelAi/
│   ├── Enums/              closed state sets
│   ├── Events/             Laravel events applications listen to
│   ├── Exceptions/
│   ├── Facades/            Harness
│   ├── Http/Controllers/   sessions, runs, approvals, artifacts, SSE
│   ├── Jobs/               execute, continue, reap, expire, prune
│   ├── Leases/             LeaseManager, Lease
│   ├── Models/             Eloquent models for querying
│   ├── Policies/           PolicyEngine, PolicyDecision
│   ├── Runtime/            RunCoordinator, EventStore, DriverRegistry, RunContext
│   ├── Sandbox/            NullSandboxProvider
│   ├── Streaming/          StreamedRun, SSE, Vercel protocol
│   ├── Support/            Id
│   ├── Testing/            ClutchFake, DriverContractTests, RecordingSink
│   ├── Tools/              ToolExecutionLedger
│   └── ValueObjects/       RunBudget, BudgetUsage, DriverCapabilities, NormalizedFailure
└── tests/
    ├── Unit/               state tables, budgets, redaction, identifiers, policy
    ├── Feature/            lifecycle, approvals, streaming, routes, console, invariants
    ├── Contracts/          the shared driver contract suite
    └── Fixtures/           agents, tools, and a user model
```

A `Repositories/` directory was considered and dropped: the models are the
query surface, and a repository layer over Eloquent would have added indirection
without adding a substitution boundary.

Namespaces should reflect responsibilities rather than implementation layers when practical. Avoid generic `Services` and `Helpers` directories.

## 4. Public API rules

### Stable identifiers

Use sortable opaque string identifiers with prefixes:

- `ses_` for sessions
- `run_` for runs
- `evt_` for events
- `apr_` for approvals
- `chk_` for checkpoints
- `art_` for artifacts
- `tcl_` for tool calls

Identifiers are generated in application code before insert so they are available to events and jobs.

### Builders

Builders are immutable or clone-on-write. They validate syntax immediately and cross-resource constraints at `create()`.

### Models

Eloquent models are available for querying, but lifecycle mutation methods are not public. State changes go through the coordinator.

### Exceptions

Expose specific domain exceptions:

- `SessionNotFound`
- `RunNotFound`
- `SessionBusy`
- `InvalidStateTransition`
- `ApprovalNotFound`
- `ApprovalAlreadyResolved`
- `CapabilityUnsupported`
- `BudgetExceeded`
- `LeaseUnavailable`
- `CheckpointIncompatible`
- `DriverFailure`
- `ArtifactUnavailable`

Exceptions must be safe to render through Laravel's exception handler. Sensitive details belong in the previous exception and protected logs.

## 5. Coding conventions

- Use `declare(strict_types=1);` in every PHP source file.
- Prefer `final` classes and `readonly` value objects.
- Use backed enums for closed state sets.
- Use interfaces only at real substitution boundaries.
- Keep Eloquent out of driver contracts.
- Pass typed command/value objects across runtime boundaries.
- Do not pass a container instance into domain objects.
- Do not place model/provider logic in queue jobs.
- Do not dispatch broadcasts inside open database transactions.
- Do not persist raw provider responses by default.
- Do not catch `Throwable` without recording a normalized failure or rethrowing.

Run formatting with Laravel Pint. Use PHPStan or Larastan at the highest practical level, initially level 8.

## 6. Database migrations

Create tables in dependency order:

1. `clutch_sessions`
2. `clutch_runs`
3. Add nullable `active_run_id` foreign key to sessions
4. `clutch_events`
5. `clutch_approvals`
6. `clutch_checkpoints`
7. `clutch_artifacts`
8. `clutch_tool_executions`

Migration requirements:

- Compatible with PostgreSQL and supported Laravel migration APIs
- Foreign keys and appropriate cascade/restrict behavior
- Composite uniqueness for event sequence and tool idempotency
- Indexes for tenant, participant, status, timestamps, and pruning
- Decimal cost fields; never floating point
- Encrypted application casts for sensitive JSON fields
- No database enum types; use strings plus application enums for portability

Run migration tests against PostgreSQL, including fresh install and rollback.

## 7. Implementation phases

> **Status.** Phases 0 through 9 are implemented and covered by the test suite.
> Phase 10 (sandbox providers and additional runtime drivers) is deliberately
> out of scope for v1 and belongs in extension packages.


### Phase 0: Package skeleton

Deliver:

- Composer package metadata and autoloading
- Service provider and facade
- Published configuration
- Testbench setup
- CI matrix for PHP and Laravel versions
- Pint and static analysis

Acceptance:

- Package installs in a blank Laravel application.
- Configuration publishes successfully.
- CI can run unit and PostgreSQL integration tests.

### Phase 1: Domain and persistence

Deliver:

- Identifiers
- Enums and value objects
- Session, run, event, approval, checkpoint, artifact, and tool-execution models
- Migrations and factories
- State transition service
- Event store

Acceptance:

- State machine and transaction invariants have unit and integration tests.
- Competing run creation allows exactly one active run.
- Terminal state and event commit atomically.
- Event sequences remain unique under concurrent appends.

### Phase 2: Driver contract and fake driver

Deliver:

- `ClutchDriver` contract
- Capability object
- Driver registry
- Driver session/checkpoint value objects
- Fake deterministic driver
- Shared driver contract test suite

Acceptance:

- The full session/run lifecycle works without Laravel AI.
- Capability mismatches fail before execution.
- Every future driver can run the same contract suite.

### Phase 3: Synchronous Laravel AI driver

Deliver:

- Agent resolution from the container
- Session creation
- Synchronous prompt execution
- Laravel AI conversation integration
- Event translation
- Structured output and usage capture
- Result object

Acceptance:

- README quick-start and continuation examples pass as feature tests.
- Two sequential turns share the correct Laravel AI conversation.
- Run result and terminal events are durable.

### Phase 4: Queues, leases, and recovery

Deliver:

- Execution jobs
- Redis lease manager with database correctness checks
- Heartbeats
- Retry classification and backoff
- Lost-worker detection command/job
- Manual retry as a new attempt

Acceptance:

- Duplicate job delivery does not duplicate execution.
- Two workers cannot run the same session concurrently.
- A stale job exits without mutating newer state.
- A recoverable run restarts from its last safe checkpoint.

### Phase 5: Streaming and replay

Deliver:

- Normalized stream event mapper
- SSE response
- Cursor replay
- Replay-to-live race closure
- Optional Laravel broadcasting adapter
- Vercel AI data protocol mapper

Acceptance:

- A client can disconnect, miss events, reconnect, and receive a gap-free ordered stream.
- Duplicate network events can be deduplicated by run and sequence.
- A terminal event closes the run stream.
- Direct and queued execution produce equivalent stored event histories.

### Phase 6: Human approval

Deliver:

- Laravel AI approval translation
- Approval broker
- Decision authorization
- Idempotent approve/reject
- Approval timeout policy
- Continue-run job

Acceptance:

- A worker exits normally while waiting.
- Approval survives deployment and process restart.
- Two simultaneous decisions resolve exactly once.
- The approved tool executes at most once.
- Rejection reaches the agent when the driver supports continuation.

### Phase 7: Cancellation and budgets

Deliver:

- Cancellation requests and signals
- Step/tool boundary enforcement
- Time, token, tool-call, step, and cost budgets
- Usage accumulation across attempts

Acceptance:

- Cancellation prevents any new step after observation.
- Budget exhaustion results in a distinct terminal state.
- Usage totals remain correct across retries.
- The package does not claim interruption of a tool that cannot be interrupted.

### Phase 8: Artifacts and tool ledger

Deliver:

- Artifact registration and metadata
- Artifact authorization hooks
- Integrity hashes
- Idempotent tool contract
- Tool execution ledger

Acceptance:

- Artifacts are associated with the correct tenant, session, and run.
- Artifact bytes are not copied into database events.
- Repeated idempotent tool delivery returns its stored result.

### Phase 9: Operations and hardening

Deliver:

- Inspection, retry, cancel, and prune commands
- Retention policies
- Metrics/events for observability
- Redaction serializers
- Security review
- Upgrade guide and changelog

Acceptance:

- Pruning never removes data required by active or resumable runs.
- Commands redact sensitive data by default.
- Tenant-isolation suite passes for all package routes and lookups.
- Chaos tests cover worker loss at each safe boundary.

### Phase 10: Optional runtime extensions

Only begin after the default driver passes all v1 acceptance tests.

Potential deliverables:

- Sandbox provider contract package
- One cloud sandbox integration
- One coding-runtime driver
- Runtime bridge protocol
- Portable workspace snapshots

## 8. Testing strategy

### Unit tests

Cover:

- State transition tables
- Budget calculations
- Capability checks
- Redaction
- Event serialization
- Identifier parsing
- Retry classification
- Permission decisions

### Feature tests

Cover:

- Facade and builder APIs
- Session/run creation
- Continuation
- Approvals
- Cancellation
- Artifacts
- Package routes
- Console commands
- Testing assertions

### Integration tests

Use real PostgreSQL and Redis for:

- Row locking
- Concurrent run creation
- Concurrent event sequences
- Queue duplication
- Lease expiration
- SSE replay
- Approval races
- Pruning

### Driver contract tests

Every driver must pass a reusable suite covering:

- Start and stop
- New turn
- Sequential turn continuity
- Streaming order
- Tool call/result pairing
- Approval or explicit unsupported result
- Checkpoint serialization and restoration
- Cancellation behavior
- Structured output or explicit unsupported result
- Terminal result consistency
- Secret exclusion

### Failure-injection tests

Inject failure:

- Before provider call
- During stream consumption
- After tool-call recording but before execution
- After tool execution but before result delivery
- Before checkpoint commit
- After checkpoint commit but before job acknowledgement
- During approval resolution
- Before terminal event broadcast

Assert that recovery follows the documented semantics and does not silently duplicate protected side effects.

### Tenant isolation tests

For every public route, facade lookup, relation, and console operation, assert that:

- Correct tenant can access.
- Different tenant cannot access.
- Unscoped lookup is unavailable or explicitly privileged.
- Broadcast channels authorize membership.
- Artifact URLs cannot be reused across tenants.

## 9. Event mapper development

Laravel AI events must map into a provider-neutral set. Keep provider-specific metadata under an optional namespaced payload field:

```json
{
  "type": "usage.updated",
  "payload": {
    "input_tokens": 100,
    "output_tokens": 30,
    "provider": {
      "openai": {
        "cached_input_tokens": 80
      }
    }
  }
}
```

Rules:

- Never change the meaning of an existing event type in a minor release.
- New optional fields are backward compatible.
- New event types are backward compatible when consumers ignore unknown types.
- Renaming or removing fields requires a major release or versioned protocol.
- Unknown provider events may be recorded as `driver.event` only after redaction.

## 10. Adding a driver

1. Create a separate namespace or extension package.
2. Implement `ClutchDriver`.
3. Declare truthful capabilities.
4. Version checkpoint schemas.
5. Normalize runtime events.
6. Keep secrets out of checkpoint and event payloads.
7. Pass the shared driver contract suite.
8. Document continuation guarantees and failure boundaries.
9. Register through configuration and the service container.

A driver should not access harness database tables directly. It communicates through typed inputs, an event sink, results, and checkpoints.

## 11. Adding a sandbox provider

1. Implement `SandboxProvider` in an extension package.
2. Make create, restore, stop, and destroy idempotent where possible.
3. Define workspace path semantics.
4. Encrypt provider checkpoint material.
5. Support network deny rules when the platform allows them.
6. Do not write harness infrastructure into the user-visible workspace.
7. Pass provider lifecycle and cleanup tests.

## 12. Backward compatibility

The following are public API:

- Facade and builder methods shown in README
- Contracts intended for driver/tool authors
- Enums and value objects used in configuration
- Database behavior visible through documented models
- Event names and envelope
- Configuration keys
- Testing fake and assertions

Internal coordinators, repositories, and jobs are not public extension points unless explicitly documented.

Before v1.0, breaking changes require a changelog entry and upgrade note. After v1.0, follow semantic versioning.

## 13. Architecture decision records

Create an ADR when changing:

- Session/run meaning
- State machines
- Event ordering or delivery
- Checkpoint portability
- Queue/lease correctness model
- Approval continuation behavior
- Driver or sandbox contracts
- Persistence technology assumptions
- Security or tenancy defaults

ADRs live in `docs/adr/NNNN-short-title.md` and contain context, decision, consequences, and alternatives.

## 14. Definition of done

A feature is complete when:

1. Public behavior is documented.
2. Success, failure, authorization, and concurrency tests pass.
3. PostgreSQL behavior is verified where persistence is involved.
4. Events and metrics are emitted.
5. Sensitive fields are redacted before persistence.
6. Queue delivery can safely repeat.
7. Static analysis and formatting pass.
8. No undocumented capability degradation occurs.
9. README examples remain executable.
10. Relevant architectural invariants remain green.

## 15. Suggested first milestone

The first milestone should demonstrate one complete vertical slice:

1. Install package in a blank Laravel application.
2. Define a Laravel AI agent using a fake provider.
3. Create a durable harness session.
4. Queue one run.
5. Record and replay progress events.
6. Pause on one approvable tool.
7. Resolve approval in a different request/process.
8. Complete the tool and agent run exactly once.
9. Return final text, usage, and one artifact.
10. Continue the same session with a second run.

Do this before expanding the API surface. It proves the hardest product promise end to end.
