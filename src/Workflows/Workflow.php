<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use Closure;
use Clutch\Laravel\Models\Artifact as ArtifactModel;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Runtime\ClutchResult;
use LogicException;

/**
 * A finite job whose control flow is yours and whose durability is the
 * harness's.
 *
 * An agent decides what to do next. A workflow does not: you write ordinary
 * PHP, and call an agent at the points where judgement is actually needed.
 * What the harness adds is that the job survives the process running it.
 * Steps are remembered, so a resume after a pause, a lost worker or a deploy
 * re-enters the body and skips the work that already happened.
 *
 * ```php
 * class OnboardCustomer extends Workflow
 * {
 *     protected static ?string $agent = ResearchAgent::class;
 *
 *     public function handle(array $payload): array
 *     {
 *         $research = $this->step('research', fn () => $this->prompt(
 *             "Research {$payload['domain']}"
 *         )->text);
 *
 *         $this->emit('researched', ['chars' => strlen($research)]);
 *
 *         $decision = $this->pause('sign-off', ['research' => $research]);
 *
 *         return $this->step('publish', fn () => $this->publish($research));
 *     }
 * }
 * ```
 */
abstract class Workflow
{
    /**
     * The agent `prompt()` uses when no class is named at the call site.
     *
     * @var class-string|null
     */
    protected static ?string $agent = null;

    /**
     * The sandbox provider this workflow's agent sessions run under.
     */
    protected static ?string $sandbox = null;

    protected ?WorkflowRuntime $runtime = null;

    /**
     * Do the work.
     *
     * Runs from the top on every resume. Anything you cannot afford to repeat
     * belongs inside `step()`.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract public function handle(array $payload): mixed;

    /**
     * Workspace paths to collect as artifacts once the body returns.
     *
     * Globs are allowed, for example `reports/*.md`.
     *
     * @return array<int, string>
     */
    public function produces(): array
    {
        return [];
    }

    /**
     * The default agent for this workflow, if it declared one.
     *
     * @return class-string|null
     */
    public static function defaultAgent(): ?string
    {
        return static::$agent;
    }

    public static function sandboxProvider(): ?string
    {
        return static::$sandbox;
    }

    // Entry points -------------------------------------------------------

    /**
     * Hand the workflow to the queue and return immediately.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function dispatch(array $payload = []): Run
    {
        return static::start()->dispatch($payload);
    }

    /**
     * Run the workflow inline and return when it settles.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function runNow(array $payload = []): ClutchResult
    {
        return static::start()->runNow($payload);
    }

    /**
     * Begin configuring a run: participant, tenant, budget, queue, and so on.
     */
    public static function start(): PendingWorkflow
    {
        return new PendingWorkflow(static::class);
    }

    /**
     * Answer a paused workflow and let it carry on, on the queue.
     *
     * @param  array<string, mixed>  $input
     */
    public static function resume(string $runId, array $input = []): Run
    {
        return app(WorkflowRunner::class)->resume($runId, $input);
    }

    /**
     * Answer a paused workflow and carry on inline.
     *
     * @param  array<string, mixed>  $input
     */
    public static function resumeNow(string $runId, array $input = []): ClutchResult
    {
        return app(WorkflowRunner::class)->resumeNow($runId, $input);
    }

    // Available while running --------------------------------------------

    /**
     * Run a unit of work once, ever.
     *
     * The first pass runs the closure and stores what it returned. Every
     * later re-entry returns the stored value without calling it again.
     * Results have to survive a JSON round trip.
     */
    protected function step(string $name, Closure $work): mixed
    {
        return $this->runtime()->step($name, $work);
    }

    /**
     * Run independent steps together, persisting each as it lands.
     *
     * A resume after a partial failure re-runs only what is still missing.
     *
     * @param  array<string, Closure>  $work
     * @return array<string, mixed>
     */
    protected function steps(array $work): array
    {
        return $this->runtime()->steps($work);
    }

    /**
     * Stop and wait for an answer.
     *
     * The run parks, a checkpoint is written and the worker exits, so nothing
     * is held open while the answer is outstanding. Call `resume()` with input
     * and the body re-enters, reaching this same call with the answer in hand.
     *
     * @param  array<string, mixed>  $data  what the decision maker should see
     * @return array<string, mixed> whatever the resume supplied
     */
    protected function pause(string $name, array $data = [], ?string $why = null): array
    {
        return $this->runtime()->pause($name, $data, $why);
    }

    /**
     * Prompt an agent.
     *
     * The agent gets a session of its own, created once per workflow run and
     * reused, so a second prompt continues the same conversation.
     *
     * @param  class-string|null  $agentClass  defaults to the declared agent
     * @param  array<string, mixed>  $options
     */
    protected function prompt(string $prompt, ?string $agentClass = null, array $options = []): ClutchResult
    {
        $agent = $agentClass ?? static::defaultAgent();

        if ($agent === null) {
            throw new LogicException(sprintf(
                '[%s] called prompt() without an agent. Declare one with '
                .'`protected static ?string $agent = YourAgent::class;` or pass a class to prompt().',
                static::class,
            ));
        }

        return $this->runtime()->prompt($prompt, $agent, $options);
    }

    /**
     * Append a fact to the run's history. Shows up in `clutch:events` and on
     * the stream alongside everything the agents did.
     *
     * @param  array<string, mixed>  $data
     */
    protected function emit(string $type, array $data = []): void
    {
        $this->runtime()->emit($type, $data);
    }

    /**
     * Write inputs into the workspace before any work begins.
     *
     * @param  array<string, string>  $files  relative path => contents
     */
    protected function stage(array $files): void
    {
        $this->runtime()->stage($files);
    }

    /**
     * Record a durable output of the run.
     */
    protected function artifact(string $name, string $contents, ?string $path = null): ArtifactModel
    {
        return $this->runtime()->artifact($name, $contents, $path);
    }

    /**
     * Pull artifacts recorded earlier in this run back into the workspace.
     *
     * @param  array<int, string>  $only
     */
    protected function restoreArtifacts(array $only = []): void
    {
        $this->runtime()->restoreArtifacts($only);
    }

    protected function workspace(): WorkflowWorkspace
    {
        return $this->runtime()->workspace();
    }

    protected function run(): Run
    {
        return $this->runtime()->run;
    }

    protected function session(): Session
    {
        return $this->runtime()->session;
    }

    /**
     * Every answer supplied by resumes so far, keyed by pause name.
     *
     * @return array<string, mixed>
     */
    protected function resumeInput(): array
    {
        return $this->runtime()->state->resumeInput;
    }

    protected function cancelled(): bool
    {
        return $this->runtime()->cancellation()->isCancelled();
    }

    // Wiring -------------------------------------------------------------

    /**
     * Attach the harness. Called by the driver, not by application code.
     *
     * @internal
     */
    public function bind(WorkflowRuntime $runtime): static
    {
        $this->runtime = $runtime;

        return $this;
    }

    protected function runtime(): WorkflowRuntime
    {
        return $this->runtime ?? throw new LogicException(sprintf(
            '[%s] is not running. Workflow helpers are only available inside handle(); '
            .'start one with %s::dispatch() or ::runNow().',
            static::class,
            class_basename(static::class),
        ));
    }
}
