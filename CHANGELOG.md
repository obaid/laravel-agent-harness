# Changelog

All notable changes to `obaid/laravel-agent-harness` are documented here.

This project follows [Semantic Versioning](https://semver.org). Before v1.0,
breaking changes require a changelog entry and an upgrade note.

## v0.1.0 — Unreleased

The first release: a durable runtime around Laravel AI agents.

### Added

- **Sessions and runs.** `Harness::agent()` builds a durable session; a session
  holds many sequential runs and exactly one active run at a time.
- **Ordered event history.** Append-only events with per-run sequence numbers,
  cursor replay, and at-least-once delivery over SSE or Laravel broadcasting.
- **Human approval.** Laravel AI approvable tools surface as durable approvals
  that survive a deploy, resolve idempotently, and resume the run in another
  process.
- **Cancellation.** Cooperative, durable, and honest about what it cannot
  interrupt.
- **Budgets.** Step, tool-call, token, cost, and duration limits that layer
  from configuration to session to run, taking the most restrictive value and
  carrying usage across attempts.
- **Artifacts.** Durable outputs on a filesystem disk with metadata, integrity
  hashes, and authorized downloads.
- **Idempotent tools.** A ledger that records a side effect before it happens,
  so a retry returns the stored result instead of repeating it.
- **Checkpoints.** Versioned, encrypted driver state, with a hard refusal to
  persist configured secrets.
- **Leases.** One coordinator per session, backed by atomic cache locks with
  the database version columns as the final authority.
- **Recovery.** Detection of runs whose worker vanished, and retry as a new
  attempt rather than reopening a terminal record.
- **Redaction before persistence.** Configured sensitive keys and per-tool
  serializers run on the way into storage, not on the way out.
- **The `laravel-ai` driver.** Runs ordinary Laravel AI agents, translating
  their stream events, conversations, approvals, usage, and structured output.
- **A driver contract.** `HarnessDriver` plus a reusable contract test suite,
  so a new runtime can be added without touching the core.
- **HTTP routes** for sessions, runs, event streams, approvals, and artifacts —
  all participant-scoped.
- **Console commands:** `harness:sessions`, `harness:run`, `harness:events`,
  `harness:retry`, `harness:cancel`, `harness:reap`, `harness:prune`, and
  `make:harness-agent`.
- **A testing fake.** `Harness::fake()` swaps in a deterministic driver and runs
  queued work inline, keeping the real coordinator, state machine, event store,
  approvals, and routes in play.
