# Changelog

Notable changes to `obaid/laravel-clutch`.

This project follows [Semantic Versioning](https://semver.org). Before v1.0, a
breaking change needs a changelog entry and an upgrade note.

## v0.1.0, unreleased

First release: a durable runtime around Laravel AI agents.

Sessions and runs arrive through `Clutch::agent()`. A session holds many runs
in sequence and exactly one active run at a time.

Every run leaves an append only event history with per run sequence numbers,
cursor replay, and at least once delivery over SSE or Laravel broadcasting.
Redaction happens on the way into storage, so a configured sensitive key never
reaches the database, and per tool serializers can narrow a payload further.

Approvable Laravel AI tools become durable approvals that survive a deploy,
resolve idempotently, and resume the run from another process. Cancellation is
cooperative and durable, and says plainly what it cannot interrupt.

Budgets cover steps, tool calls, tokens, cost, and duration. They layer from
configuration to session to run, taking the more restrictive value at each step
and carrying usage across attempts.

Artifacts live on a filesystem disk with metadata, integrity hashes, and
authorized downloads. The tool ledger records a side effect before it happens,
so a retry returns the stored result instead of repeating it. Checkpoints hold
versioned, encrypted driver state, and the store refuses to persist a
configured secret rather than quietly stripping it.

Leases give one coordinator per session, backed by atomic cache locks with the
database version columns as the final authority. Runs whose worker vanished are
detected and retried as a new attempt, leaving the terminal record alone.

The bundled `laravel-ai` driver runs ordinary Laravel AI agents, translating
their stream events, conversations, approvals, usage, and structured output. The
`ClutchDriver` contract and its reusable test suite let a new runtime be added
without touching the core.

Skills are reusable instruction bundles an agent reaches for when a task calls
for one. The model sees each skill's name and description and pulls in the body
of the one it needs, so a library of them costs a line each rather than their
full weight on every turn. They can be registered inline or discovered from a
directory of SKILL.md files.

Long runs can hand a turn back at a step or wall-clock boundary and be re-queued
to continue, which lets a run outlive a queue worker's timeout instead of being
killed part-way through a step. Usage accumulates across slices. Only a driver
declaring the time_slicing capability accepts limits; the bundled laravel-ai
driver does not, because Laravel AI cannot park a turn it abandoned.

Loop guards catch the failure budgets miss: an agent calling the same tool with
the same arguments over and over. Past a threshold the model is reminded, and
past a higher one the call is refused with the reason as its result. Tool
deadlines bound a single call, which a run-level duration budget cannot do.

Oversized tool output is written to an artifact and replaced with a bounded
preview plus the artifact id, keeping one chatty tool from filling the context
window. Compaction summarizes the middle of a long conversation using Laravel
AI's SummarizeAgent, keeping the earliest and most recent turns.

Sessions can name an allow list or deny list of tools, applied before the
permission mode rather than instead of it.

Also included: participant scoped HTTP routes for sessions, runs, event streams,
approvals, and artifacts; the commands `clutch:sessions`, `clutch:run`,
`clutch:events`, `clutch:retry`, `clutch:cancel`, `clutch:reap`,
`clutch:prune`, and `make:clutch-agent`; and `Clutch::fake()`, which swaps in
a deterministic driver and runs queued work inline while leaving the coordinator,
state machine, event store, approvals, and routes real.
