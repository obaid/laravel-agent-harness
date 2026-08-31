# Changelog

Notable changes to `obaid/laravel-clutch`.

This project follows [Semantic Versioning](https://semver.org). Before v1.0, a
breaking change needs a changelog entry and an upgrade note.

## v0.5.2 - 2026-08-31

A working run stops being mistaken for a dead one.

`runs.heartbeat_at` was written once, when a run transitioned to Running, and
never again. The reaper reads a heartbeat older than
`clutch.recovery.stale_after_seconds` as a worker that died, and its own comment
says the lease is the liveness check — but nothing renews that either, and it
expires after 60 seconds. So any workflow that spent longer than the threshold
inside its own steps was reaped **while it was working**, marked `worker_lost`,
and retried into exactly the same wall. Two runs of a real scan died five
minutes in, to the second, judging the same topics three times between them.

- The runtime now beats the heartbeat at every step and wave boundary, before
  blocking as well as after. It is written through the query builder: a
  liveness signal must not fire model events or touch `updated_at`.
- `clutch.recovery.stale_after_seconds` defaults to 1200, up from 300. It has
  to clear the longest a healthy worker can legitimately go without speaking,
  which is one concurrent step — `clutch.workflows.step_timeout`, 900. A
  threshold under that reaps workers mid-flight. Recovering a genuinely dead
  run slowly is the cheaper mistake.

Applications that lowered `stale_after_seconds` should check it still exceeds
`workflows.step_timeout`.

## v0.5.1 - 2026-08-31

Concurrent steps get a timeout worth the name.

`steps()` handed its work to Laravel's concurrency driver without a timeout,
which left Symfony's process default of 60 seconds — a number nobody chose and
no model-priced step can meet. A fan-out of agent calls was killed silently
mid-flight, and because the driver kills the process rather than failing it,
the run died as `worker_lost` with nothing in `failed_jobs` to say why.

Worse, the fallback answered it. The `catch` around `Concurrency::run()` exists
for closures a forked driver cannot serialise, and it re-runs the wave in the
current process. Answering a *timeout* that way runs work that was already too
slow, sequentially, in the worker — which blew the worker's own timeout and had
it SIGKILLed still holding the run. One slow step became a lost run, and the
retry repeated it exactly.

- New `clutch.workflows.step_timeout` (default 900 seconds, `null` to remove
  the limit), passed through to the driver.
- A `ProcessTimedOutException` is now raised rather than swallowed into the
  sequential fallback. Finished siblings stay journalled and a retry re-runs
  only what is missing. Serialisation failures still fall back as before.

No configuration change is required: the new default is fifteen times the limit
it replaces. Applications whose steps legitimately run longer should raise it,
keeping it under the queue worker's timeout.

## v0.5.0 - 2026-08-27

Workflows slice.

The workflow driver now declares `time_slicing` and means it: the runtime
tracks each slice's executed steps and wall-clock against the turn's limits
and, at the first step or wave boundary past the budget, hands the turn back.
The run suspends with everything finished already persisted, the coordinator
queues the continuation itself, and the next job re-enters `handle()` with
the finished steps cached. A workflow whose wall-clock exceeds any single
worker's timeout completes as a chain of sub-timeout jobs instead of being
SIGKILLed mid-flight — the failure a real 40-minute scan hit twice while this
was being built.

Two rules keep it safe: replayed steps never count toward a slice, so a
resumed workflow cannot suspend without progressing; and a slice always
completes at least one step, so a budget tighter than a single step still
moves forward one step per job. `PendingWorkflow` gains the same
`sliceAfterSteps()` / `sliceAfterSeconds()` knobs `SessionBuilder` has;
`clutch.limits.*` applies to workflows exactly as it already did to agents.

## v0.4.1 - 2026-08-27

Fixes shaken out by running a real application on the harness.

**Dated model snapshots priced at their base rate.** `gpt-4o-2024-08-06`-style
ids matched no pricing key, so every run costed $0.00 and a `maxCostUsd`
budget could never fire.

**`steps()` concurrency actually runs concurrently.** The exception wrapper
put two closures on one source line; `ReflectionClosure` locates a closure by
file and line, serialized the wrong one, and every subprocess died with "too
few arguments" — so the whole fan-out silently ran sequentially. Watched
live: a four-minute fan-out became a forty-minute crawl that blew through its
queue worker's timeout. The fallback also `report()`s its cause now instead
of swallowing it.

**`steps()` persists in waves.** State was persisted once after every sibling
resolved, making the whole group one unit of loss: a worker killed outright
mid-group erased every finished sibling's record even though their side
effects had landed, and the retry paid to run all of them again. Waves
(`clutch.workflows.step_wave_size`, default 8) bound the loss; without
concurrency the wave is a single step.

## v0.4.0 - 2026-08-25

Testing that goes after the kind of bug this package actually has.

Every defect found since the first release has been a seam, not a unit: a
component that worked perfectly and was never called, a rule that five paths
followed and a sixth did not, a happy path with no unhappy twin. Line coverage
would have called all of them covered. So the suite now attacks those shapes
directly, and doing that turned up two more.

**Every shipped driver is now run through the driver contract.** The contract
suite existed from the start and only the fake was ever put through it, which
is backwards: the fake is the one driver whose behaviour is already obvious.
Running the real two through it showed the suite itself was wrong, resuming a
session that had never taken a turn, which no coordinator does. It now drives a
turn first, and asserts a continuation stays inside the session it was given.

**Terminal-state invariants are asserted against every route to them.** Five
rules, checked against all five ways a run can finish. This found the second
half of the bug fixed in v0.3.2: the reaper left the *session* in
`awaiting_approval` describing a turn that no longer existed, because every
finalizer resets it and the reaper did not.

**Workflows are fault-injected at every boundary.** Paused, failed, reaped and
cancelled at each step in turn, asserting the only thing that matters: nothing
happened twice. This found that resuming an already-finished workflow threw
instead of being a no-op, which a double-clicked approve button would hit.
Resume is now idempotent, matching what approvals already promised.

**Wiring is tested separately from behaviour.** Whether a protection is
reachable is a different question from whether it works, and only the second
was ever asked. These assert the first: tools are wrapped inside a run,
approvable tools stay approvable through the wrapper, tools keep their names,
the ledger is written to by a real call, every service resolves, every
documented command is registered.

### An agent whose tools skip the harness now says so

Laravel AI resolves an agent's tools itself, so there is no seam to enforce
`Clutch::policy()` from. An agent that returns tools directly still works: it
calls the model, it runs the tools, and it silently gets no ledger, no approval
pause, no loop guard, no deadline and no spill.

That silence is this package's most repeated footgun and it shipped once as a
release where every protection was inert. A run cannot be failed for it, since
an agent with no tools is perfectly valid, but it no longer passes without
comment: a turn that never consulted the policy logs a warning naming the agent.

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
