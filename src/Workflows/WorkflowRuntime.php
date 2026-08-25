<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use Closure;
use Clutch\Laravel\Artifacts\Artifact;
use Clutch\Laravel\Contracts\DriverEventSink;
use Clutch\Laravel\Enums\EventType;
use Clutch\Laravel\Models\Artifact as ArtifactModel;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Runtime\CancellationSignal;
use Clutch\Laravel\Runtime\ClutchResult;
use Illuminate\Support\Facades\Concurrency;
use Throwable;

/**
 * What a workflow body is allowed to touch while it runs.
 *
 * The workflow class stays a plain object with a `handle()` method. Everything
 * that needs the harness goes through here, which is what makes a workflow
 * testable without a database and re-entrant without surprises.
 */
final class WorkflowRuntime
{
    protected ?WorkflowWorkspace $workspace = null;

    /** The step currently executing, for naming a failure. */
    protected ?string $currentStep = null;

    /**
     * @param  Closure(WorkflowState): void  $persist  writes a checkpoint mid-run
     * @param  Closure(string, string, array<string, mixed>): ClutchResult  $prompt
     */
    public function __construct(
        public readonly Run $run,
        public readonly Session $session,
        public readonly WorkflowState $state,
        protected DriverEventSink $events,
        protected CancellationSignal $cancellation,
        protected Closure $persist,
        protected Closure $prompt,
    ) {}

    // Steps --------------------------------------------------------------

    /**
     * Run a unit of work once, ever.
     *
     * On the first pass the closure runs and its result is stored. On any
     * later re-entry, whether after a pause, a lost worker or a redeploy, the
     * stored result is returned and the closure is not called again. That is
     * the whole contract: put anything you cannot afford to repeat in a step.
     */
    public function step(string $name, Closure $work): mixed
    {
        if ($this->state->hasStep($name)) {
            $this->events->emit(EventType::StepCompleted, [
                'step' => $name,
                'workflow' => $this->state->workflow,
                'replayed' => true,
            ]);

            return $this->state->step($name);
        }

        $this->throwIfCancelled();

        $this->events->emit(EventType::StepStarted, [
            'step' => $name,
            'workflow' => $this->state->workflow,
        ]);

        $previous = $this->currentStep;
        $this->currentStep = $name;

        // Deliberately not a `finally`: unwinding must leave the failing step
        // name in place, because that is the one thing the stack trace alone
        // will not tell you.
        $value = $work();

        $this->currentStep = $previous;

        $this->state->recordStep($name, $value);

        // Persisted before the next step begins, so a crash between steps
        // never costs the one that just finished.
        ($this->persist)($this->state);

        $this->events->emit(EventType::StepCompleted, [
            'step' => $name,
            'workflow' => $this->state->workflow,
            'replayed' => false,
        ]);

        return $value;
    }

    /**
     * Run independent steps together, persisting each as it lands.
     *
     * A resume after a partial failure re-runs only the ones still missing.
     * Concurrency is Laravel's, so it obeys whatever driver the application
     * configured, including `sync` in tests.
     *
     * @param  array<string, Closure>  $work
     * @return array<string, mixed>
     */
    public function steps(array $work): array
    {
        $outstanding = array_filter(
            $work,
            fn (string $name): bool => ! $this->state->hasStep($name),
            ARRAY_FILTER_USE_KEY,
        );

        foreach (array_keys($work) as $name) {
            if (! isset($outstanding[$name])) {
                $this->events->emit(EventType::StepCompleted, [
                    'step' => $name,
                    'workflow' => $this->state->workflow,
                    'replayed' => true,
                ]);
            }
        }

        if ($outstanding !== []) {
            $this->throwIfCancelled();

            foreach (array_keys($outstanding) as $name) {
                $this->events->emit(EventType::StepStarted, [
                    'step' => $name,
                    'workflow' => $this->state->workflow,
                    'concurrent' => true,
                ]);
            }

            foreach ($this->resolveConcurrently($outstanding) as $name => $value) {
                $this->state->recordStep($name, $value);

                $this->events->emit(EventType::StepCompleted, [
                    'step' => $name,
                    'workflow' => $this->state->workflow,
                    'replayed' => false,
                    'concurrent' => true,
                ]);
            }

            ($this->persist)($this->state);
        }

        $results = [];

        foreach (array_keys($work) as $name) {
            $results[$name] = $this->state->step($name);
        }

        return $results;
    }

    /**
     * @param  array<string, Closure>  $work
     * @return array<string, mixed>
     */
    protected function resolveConcurrently(array $work): array
    {
        if (! (bool) config('clutch.workflows.concurrent_steps', true)) {
            return array_map(static fn (Closure $task): mixed => $task(), $work);
        }

        try {
            /** @var array<string, mixed> $resolved */
            $resolved = Concurrency::run($work);

            return $resolved;
        } catch (Throwable) {
            // A forked driver cannot always serialise what the closure closes
            // over. Falling back keeps the workflow correct, just slower.
            return array_map(static fn (Closure $task): mixed => $task(), $work);
        }
    }

    // Pausing ------------------------------------------------------------

    /**
     * Stop and wait for someone to answer.
     *
     * The run parks, a checkpoint is written and the worker exits. Nothing
     * holds a connection or a transaction while the answer is outstanding.
     *
     * @param  array<string, mixed>  $data  what the decision maker should see
     * @return array<string, mixed> the input the resume supplied
     *
     * @throws WorkflowPaused
     */
    public function pause(string $name, array $data = [], ?string $why = null): array
    {
        if (array_key_exists($name, $this->state->resumeInput)) {
            /** @var array<string, mixed> $answered */
            $answered = (array) $this->state->resumeInput[$name];

            return $answered;
        }

        throw new WorkflowPaused($name, $data, $why);
    }

    // Agents -------------------------------------------------------------

    /**
     * Prompt an agent from inside the workflow.
     *
     * The agent gets its own session, created once per workflow run and
     * reused, so a second prompt to the same agent continues the same
     * conversation rather than starting over.
     *
     * @param  array<string, mixed>  $options
     */
    public function prompt(string $prompt, string $agentClass, array $options = []): ClutchResult
    {
        return ($this->prompt)($prompt, $agentClass, $options);
    }

    // Workspace ----------------------------------------------------------

    public function workspace(): WorkflowWorkspace
    {
        return $this->workspace ??= new WorkflowWorkspace($this->run->session_id);
    }

    /**
     * Write inputs into the workspace before any work begins.
     *
     * @param  array<string, string>  $files  relative path => contents
     */
    public function stage(array $files): void
    {
        foreach ($files as $path => $contents) {
            $this->workspace()->put($path, $contents);
        }

        $this->state->staged = true;

        $this->events->emitRaw('workflow.staged', [
            'workflow' => $this->state->workflow,
            'files' => array_keys($files),
        ]);
    }

    /**
     * Record a durable output of the run.
     */
    public function artifact(string $name, string $contents, ?string $path = null): ArtifactModel
    {
        $model = app(\Clutch\Laravel\Artifacts\ArtifactManager::class)->add(
            $this->run,
            Artifact::fromContents($contents, $path ?? $name)->name($name),
        );

        $this->state->artifacts[$name] = $model->id;

        return $model;
    }

    /**
     * Collect workspace files matching the workflow's `produces()` patterns
     * into artifacts. Called for you once the body returns.
     *
     * @param  array<int, string>  $patterns
     * @return array<int, ArtifactModel>
     */
    public function collect(array $patterns): array
    {
        $collected = [];

        foreach ($patterns as $pattern) {
            foreach ($this->workspace()->match($pattern) as $relative) {
                $contents = $this->workspace()->get($relative);

                if ($contents === null || isset($this->state->artifacts[$relative])) {
                    continue;
                }

                $collected[] = $this->artifact($relative, $contents, $relative);
            }
        }

        return $collected;
    }

    /**
     * Pull artifacts recorded earlier in this run back into the workspace, so
     * a later stage can read what an earlier one produced.
     *
     * @param  array<int, string>  $only  artifact names, or all of them
     */
    public function restoreArtifacts(array $only = []): void
    {
        $names = $only === [] ? array_keys($this->state->artifacts) : $only;

        foreach ($names as $name) {
            $id = $this->state->artifacts[$name] ?? null;

            if ($id === null) {
                continue;
            }

            $model = ArtifactModel::query()->find($id);

            if ($model === null) {
                continue;
            }

            if (! $model->exists()) {
                continue;
            }

            $this->workspace()->put($name, $model->contents());
        }
    }

    // Events -------------------------------------------------------------

    /**
     * Append a fact to the run's history.
     *
     * @param  array<string, mixed>  $data
     */
    public function emit(string $type, array $data = []): void
    {
        $this->events->emitRaw('workflow.'.$type, [
            'workflow' => $this->state->workflow,
            ...$data,
        ]);
    }

    public function cancellation(): CancellationSignal
    {
        return $this->cancellation;
    }

    /**
     * Stop at a step boundary if cancellation has been requested.
     *
     * Checked between steps rather than inside them, because a step that has
     * begun is not safe to abandon halfway.
     *
     * @throws WorkflowCancelled
     */
    public function throwIfCancelled(): void
    {
        if ($this->cancellation->isCancelled()) {
            throw new WorkflowCancelled($this->cancellation->reason());
        }
    }

    /**
     * The step that was running when something raised, for naming a failure.
     */
    public function currentStep(): ?string
    {
        return $this->currentStep;
    }
}
