# Changelog

Notable changes to `obaid/laravel-clutch`.

This project follows [Semantic Versioning](https://semver.org). Before v1.0, a
breaking change needs a changelog entry and an upgrade note.

## v0.3.2 - 2026-08-25

**A reaped run left its approvals pending forever.** The reaper transitions a
run straight to failed rather than going through a finalizer, so the
`cancelPending` that every finalizer calls never happened. An approval inbox
would keep showing an item for a run nobody could ever resume.

Cancelling pending approvals now happens inside `transitionRun` whenever a run
reaches a terminal state, so it holds for every caller rather than for the ones
that remembered. A finished run has nothing outstanding to decide, and that is
now an invariant with a test rather than a convention.

## v0.3.1 - 2026-08-25

Fixes found by building a real workflow against the published package, which
is the same exercise that caught the three criticals before v0.2.0.

**An agent that paused inside a workflow step was ignored.** The workflow
carried on as though the prompt had returned, recorded the step as finished,
and left the agent's approval pending forever with nothing that would ever
resolve it. The pause now travels outward: the workflow's run parks showing the
agent's real tool call, the step is left unrecorded, and resuming delivers the
decision to the agent so its run continues from where it stopped. A rejection
travels the same way rather than stranding the run.

**Workflow state was not persisted when a run parked or failed.** Only a
completed step wrote a checkpoint, so a pause lost the record of which agent
sessions already existed, and a resume created a second one while the first sat
waiting. State is now persisted on every non-completed exit, which also means a
retry after a failure keeps the steps that had finished.

**A failing concurrent step discarded its siblings' results.** `steps()` only
recorded once the whole group had returned, so one failure threw away work that
had succeeded, contrary to what the guide promised. Failures are now collected
per step, the successes are recorded and persisted, and the first failure is
raised afterwards.

**The workflow driver did not resolve from a published config.** An application
that published `clutch.php` before v0.3.0 upgraded into a `DriverNotFound`.
Workflows are a built-in runtime rather than a provider to choose between, so
they are now registered in code and no longer depend on the user's config file
being current.

## v0.3.0 - 2026-08-25

Adds workflows.

Until now everything in Clutch was agent-driven: you prompt, and the model
decides what happens next. That is the right shape when the model genuinely
owns the plan, and the wrong one when you already know the plan and only need
judgement in the middle of it.

A workflow is a finite job whose control flow is ordinary PHP. You call an
agent where a decision actually needs making, and the harness makes the job
survive the process running it.

```php
class OnboardCustomer extends Workflow
{
    public function handle(array $payload): mixed
    {
        $research = $this->step('research', fn () => $this->prompt(...)->text);

        $decision = $this->pause('sign-off', ['research' => $research]);

        return $this->step('provision', fn () => $this->provision($payload));
    }
}
```

`handle()` re-enters from the top on every resume, and `step()` is what makes
that safe: the closure runs once ever, and later passes return the stored
result without calling it. Put a charge in a step and it happens once, however
many times the job restarts. Step results are persisted before the next step
begins, so a worker lost between two steps never costs the one that finished.

`steps()` runs independent work together through Laravel's concurrency driver,
persisting each result as it lands, so a resume after a partial failure re-runs
only what is missing. `pause()` parks the run and releases the worker, and
`resume()` answers it from anywhere. A rejection comes back to the workflow as
an answer rather than killing the run, because what a rejection means is the
workflow's decision and not the harness's.

Workflows also stage inputs into a per-run workspace, declare expected outputs
with `produces()`, record artifacts, and restore earlier ones for a later
stage.

A workflow is implemented as an ordinary driver, so it inherits leases,
budgets, cancellation, the event log, streaming, retries and the orphan reaper
rather than reimplementing any of them. `clutch:events` shows steps, pauses and
the agents' own activity in one ordered history.

Two smaller fixes came out of building it. `Clutch::fake()` no longer replaces
the workflow driver, since a workflow is application control flow rather than a
model call and faking it would replace the thing under test. And a run's input
now reaches its driver in full, rather than only the prompt string.

New: `make:clutch-workflow`.

## v0.2.1 - 2026-08-25

Nothing in the runtime changed. This is packaging and documentation for the
public release.

Laravel 13 is now covered by CI. The composer constraint and the README have
claimed Laravel 12 or 13 since the first release, but the test matrix only ever
ran 12, so the claim was true and unguarded. The matrix now runs both, at both
`prefer-lowest` and `prefer-stable`.

Added setup prompts for coding agents at
[/agents/](https://obaid.github.io/laravel-clutch/agents/), an `llms.txt` index
at the docs root, and an `llms-full.txt` holding the guide and every recipe as
one file. Both are generated from the same sources as the site.

Also adds a logo, contribution and security policies, issue templates, and a
Used by section. Corrects the home page, which described the `laravel/ai`
constraint as "0.11 or newer" when `^0.11` resolves to 0.11.x only.

## v0.2.0 - 2026-08-25

Makes the tool protections actually run.

The ledger, the loop guards, the tool deadlines and the spill policy were all
built, tested in isolation, and never invoked. Laravel AI executes tools inside
its own generation loop, so there was no point at which Clutch saw a call, and
nothing ever wrote to the tool execution table. In practice that meant
`IdempotentTool` did nothing unless an application called the ledger by hand,
and the guarantee that a retried tool does not repeat its side effect was not
true of an ordinary agent run.

Tools passed through `Clutch::policy()` are now wrapped in a decorator that puts
the ledger, the guards and the spill policy in front of the call. The wrapper is
transparent: it forwards the name, description and schema, and stays approvable
when the tool it wraps is, because Laravel AI decides whether a call pauses by
checking that interface.

Tool naming is also reconciled. The policy engine used a snake_cased name while
Laravel AI, approvals and events used the class basename, so configuration keys
and real tool names disagreed. One name is now used everywhere, and
configuration accepts either spelling.

Loop guard counters are keyed by run, so a long-lived queue worker cannot carry
one run's repetition into the next.

Upgrading: agents must pass their tools through `Clutch::policy()` for any of
this to apply, which `make:clutch-agent` has always generated. Tool names in
`clutch.permissions.tools` may stay snake_case.

Found by building a CRM demo and watching an approved discount fire twice.

## v0.1.2 - 2026-08-25

Fixes the event stream so the obvious client works.

Frames were emitted as `id:`, `event: {type}`, `data: {...}`. Naming the SSE
event stops `EventSource.onmessage` from firing at all, so a client had to call
`addEventListener` for every event type it might ever see. The documented
example used `onmessage`, which means it never worked.

Frames now carry `id:` and `data:` only. The type was always on the payload, so
nothing is lost, and `id:` still carries the sequence for `Last-Event-ID`
resumption. A client that registered per-type listeners needs to switch on
`event.type` from the payload instead.

Found by building a chat UI against the package, where the stream is the entire
interface and the failure was immediately visible.

## v0.1.1 - 2026-08-25

Fixes a bug that made the bundled driver unusable from the published config.

`config/clutch.php` imported `Clutch\Laravel\Drivers\LaravelAiDriver`, which
does not exist: the class is `Clutch\Laravel\Drivers\LaravelAi\LaravelAiDriver`.
Any application that used the default driver hit "No driver is registered under
the name [laravel-ai]" on its first run.

The test suite missed it because it replaced `clutch.drivers` wholesale with its
own map, so the shipped file was never loaded. Tests now extend the shipped
config instead of overwriting it, and a new suite loads the published file
directly and checks that every class it names exists and implements the driver
contract. A misconfigured driver now also names the offending class in the
error rather than only the driver.

## v0.1.0 - 2026-08-24

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
