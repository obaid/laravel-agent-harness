<?php

declare(strict_types=1);

namespace Clutch\Laravel\Testing;

use Closure;
use Clutch\Laravel\ClutchManager;
use Clutch\Laravel\Drivers\FakeDriver;
use Clutch\Laravel\Enums\ApprovalStatus;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Artifact;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\Runtime\DriverRegistry;
use Clutch\Laravel\Runtime\RunCoordinator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * A deterministic harness for tests.
 *
 * Every driver resolves to the scripted fake and queued runs execute inline, so
 * a test exercises the real coordinator, event store, approvals and artifacts
 * against a model that never calls a provider.
 */
class ClutchFake extends ClutchManager
{
    protected FakeDriver $driver;

    /**
     * @param  array<array-key, mixed>  $responses
     */
    public function __construct(
        Container $container,
        RunCoordinator $coordinator,
        DriverRegistry $drivers,
        array $responses = [],
    ) {
        parent::__construct($container, $coordinator, $drivers);

        $this->driver = new FakeDriver($responses);
    }

    /**
     * Point every configured driver name at the fake and run queued work inline.
     */
    public function bind(): static
    {
        foreach ([...$this->drivers->names(), 'laravel-ai', 'fake'] as $name) {
            $this->drivers->register($name, $this->driver);
        }

        // Queued runs complete within the test rather than piling up in a fake
        // queue, so an assertion about the outcome is meaningful.
        $this->container['config']->set('clutch.queue.connection', 'sync');

        return $this;
    }

    /**
     * Replace the scripted responses.
     *
     * @param  array<array-key, mixed>  $responses
     */
    public function script(array $responses): static
    {
        $this->driver->script($responses);

        return $this;
    }

    /**
     * The underlying fake driver.
     */
    public function driver(): FakeDriver
    {
        return $this->driver;
    }

    // Assertions ---------------------------------------------------------

    /**
     * Assert a session was created, optionally for a specific agent.
     *
     * @param  (Closure(Session): bool)|null  $callback
     */
    public function assertSessionCreated(?string $agentClass = null, ?Closure $callback = null): static
    {
        $sessions = $this->sessions()
            ->when($agentClass !== null, fn (Collection $all): Collection => $all->where('agent_class', $agentClass))
            ->when($callback !== null, fn (Collection $all): Collection => $all->filter($callback));

        PHPUnit::assertTrue(
            $sessions->isNotEmpty(),
            $agentClass === null
                ? 'Expected a harness session to have been created, but none were.'
                : "Expected a harness session for [{$agentClass}], but none was created. ".
                  $this->describeSessions(),
        );

        return $this;
    }

    public function assertNoSessionCreated(): static
    {
        PHPUnit::assertTrue(
            $this->sessions()->isEmpty(),
            'Expected no harness sessions to have been created. '.$this->describeSessions(),
        );

        return $this;
    }

    /**
     * Assert a run was queued, optionally matching a prompt.
     *
     * @param  (Closure(Run): bool)|string|null  $prompt
     */
    public function assertRunQueued(Closure|string|null $prompt = null): static
    {
        $runs = $this->matchRuns($prompt)->filter(
            fn (Run $run): bool => $run->queued_at !== null,
        );

        PHPUnit::assertTrue(
            $runs->isNotEmpty(),
            'Expected a harness run to have been queued'.$this->describeExpectation($prompt).'. '.$this->describeRuns(),
        );

        return $this;
    }

    /**
     * @param  (Closure(Run): bool)|string|null  $prompt
     */
    public function assertRunCompleted(Closure|string|null $prompt = null): static
    {
        return $this->assertRunReached(RunStatus::Completed, $prompt);
    }

    /**
     * @param  (Closure(Run): bool)|string|null  $prompt
     */
    public function assertRunFailed(Closure|string|null $prompt = null): static
    {
        return $this->assertRunReached(RunStatus::Failed, $prompt);
    }

    /**
     * @param  (Closure(Run): bool)|string|null  $prompt
     */
    public function assertRunCancelled(Closure|string|null $prompt = null): static
    {
        return $this->assertRunReached(RunStatus::Cancelled, $prompt);
    }

    /**
     * @param  (Closure(Run): bool)|string|null  $prompt
     */
    public function assertRunExceededBudget(Closure|string|null $prompt = null): static
    {
        return $this->assertRunReached(RunStatus::BudgetExceeded, $prompt);
    }

    /**
     * @param  (Closure(Run): bool)|string|null  $prompt
     */
    public function assertRunAwaitingApproval(Closure|string|null $prompt = null): static
    {
        return $this->assertRunReached(RunStatus::AwaitingApproval, $prompt);
    }

    /**
     * Assert the harness paused for approval of a specific tool.
     */
    public function assertApprovalRequested(string $tool): static
    {
        $approvals = Approval::query()->where('tool_name', $tool)->get();

        PHPUnit::assertTrue(
            $approvals->isNotEmpty(),
            "Expected an approval to have been requested for the tool [{$tool}]. ".$this->describeApprovals(),
        );

        return $this;
    }

    /**
     * Assert nothing is waiting on a human.
     */
    public function assertNothingAwaitingApproval(): static
    {
        $pending = Approval::query()->where('status', ApprovalStatus::Pending->value)->get();

        PHPUnit::assertTrue(
            $pending->isEmpty(),
            'Expected nothing to be awaiting approval, but found: '.
            $pending->map->tool_name->join(', '),
        );

        return $this;
    }

    /**
     * Assert an approval was resolved as approved.
     */
    public function assertApproved(string $tool): static
    {
        PHPUnit::assertTrue(
            Approval::query()->where('tool_name', $tool)->where('status', ApprovalStatus::Approved->value)->exists(),
            "Expected the tool [{$tool}] to have been approved. ".$this->describeApprovals(),
        );

        return $this;
    }

    /**
     * Assert an approval was resolved as rejected.
     */
    public function assertRejected(string $tool): static
    {
        PHPUnit::assertTrue(
            Approval::query()->where('tool_name', $tool)->where('status', ApprovalStatus::Rejected->value)->exists(),
            "Expected the tool [{$tool}] to have been rejected. ".$this->describeApprovals(),
        );

        return $this;
    }

    /**
     * Assert an artifact was attached, optionally by name.
     */
    public function assertArtifactCreated(?string $name = null): static
    {
        $artifacts = Artifact::query()
            ->when($name !== null, fn ($query) => $query->where('name', $name))
            ->get();

        PHPUnit::assertTrue(
            $artifacts->isNotEmpty(),
            $name === null
                ? 'Expected an artifact to have been created, but none were.'
                : "Expected an artifact named [{$name}]. Found: ".
                  (Artifact::query()->pluck('name')->join(', ') ?: 'none'),
        );

        return $this;
    }

    /**
     * Assert how many prompts reached the driver.
     */
    public function assertPromptedTimes(int $times): static
    {
        PHPUnit::assertCount(
            $times,
            $this->driver->prompts,
            "Expected the harness to have been prompted {$times} time(s).",
        );

        return $this;
    }

    public function assertNothingPrompted(): static
    {
        return $this->assertPromptedTimes(0);
    }

    /**
     * Assert an event type was recorded for any run.
     */
    public function assertEventRecorded(string $type): static
    {
        PHPUnit::assertTrue(
            \Clutch\Laravel\Models\RunEvent::query()->where('type', $type)->exists(),
            "Expected an event of type [{$type}] to have been recorded.",
        );

        return $this;
    }

    // Internals ----------------------------------------------------------

    /**
     * @param  (Closure(Run): bool)|string|null  $prompt
     */
    protected function assertRunReached(RunStatus $status, Closure|string|null $prompt): static
    {
        $runs = $this->matchRuns($prompt)->where('status', $status);

        PHPUnit::assertTrue(
            $runs->isNotEmpty(),
            "Expected a harness run to have reached [{$status->value}]".
            $this->describeExpectation($prompt).'. '.$this->describeRuns(),
        );

        return $this;
    }

    /**
     * @param  (Closure(Run): bool)|string|null  $prompt
     * @return Collection<int, Run>
     */
    protected function matchRuns(Closure|string|null $prompt): Collection
    {
        $runs = Run::query()->get();

        return match (true) {
            $prompt === null => $runs,
            $prompt instanceof Closure => $runs->filter($prompt),
            default => $runs->filter(fn (Run $run): bool => str_contains($run->promptText(), $prompt)),
        };
    }

    /**
     * @return Collection<int, Session>
     */
    protected function sessions(): Collection
    {
        return Session::query()->get();
    }

    protected function describeExpectation(Closure|string|null $prompt): string
    {
        return match (true) {
            $prompt === null, $prompt instanceof Closure => '',
            default => " for a prompt containing [{$prompt}]",
        };
    }

    protected function describeRuns(): string
    {
        $runs = Run::query()->get();

        if ($runs->isEmpty()) {
            return 'No runs were created at all.';
        }

        return 'Runs recorded: '.$runs
            ->map(fn (Run $run): string => "{$run->status->value} (\"".\Illuminate\Support\Str::limit($run->promptText(), 40).'")')
            ->join('; ');
    }

    protected function describeSessions(): string
    {
        $sessions = $this->sessions();

        return $sessions->isEmpty()
            ? 'No sessions were created at all.'
            : 'Sessions recorded: '.$sessions->map->agent_class->filter()->join(', ');
    }

    protected function describeApprovals(): string
    {
        $approvals = Approval::query()->get();

        return $approvals->isEmpty()
            ? 'No approvals were recorded at all.'
            : 'Approvals recorded: '.$approvals
                ->map(fn (Approval $a): string => "{$a->tool_name} ({$a->status->value})")
                ->join(', ');
    }
}
