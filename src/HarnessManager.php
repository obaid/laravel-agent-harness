<?php

declare(strict_types=1);

namespace AgentHarness\Laravel;

use AgentHarness\Laravel\Exceptions\RunNotFound;
use AgentHarness\Laravel\Exceptions\SessionNotFound;
use AgentHarness\Laravel\Models\Approval;
use AgentHarness\Laravel\Models\Run;
use AgentHarness\Laravel\Models\Session;
use AgentHarness\Laravel\Policies\PolicyAwareTools;
use AgentHarness\Laravel\Runtime\DriverRegistry;
use AgentHarness\Laravel\Runtime\RunCoordinator;
use AgentHarness\Laravel\Runtime\SessionBuilder;
use AgentHarness\Laravel\Testing\HarnessFake;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * The public entry point behind the Harness facade.
 *
 * Creates and loads sessions and runs, and hands lifecycle work to the
 * coordinator. It deliberately owns no state transitions of its own.
 */
class HarnessManager
{
    public function __construct(
        protected Container $container,
        protected RunCoordinator $coordinator,
        protected DriverRegistry $drivers,
    ) {}

    /**
     * Begin building a session for a Laravel AI agent.
     *
     * @param  class-string  $agentClass
     */
    public function agent(string $agentClass): SessionBuilder
    {
        return $this->builder()->agent($agentClass);
    }

    /**
     * Begin building a session for a named runtime.
     */
    public function runtime(string $runtime): SessionBuilder
    {
        return $this->builder()->runtime($runtime);
    }

    /**
     * Begin building a session, choosing the agent or runtime later.
     */
    public function builder(): SessionBuilder
    {
        return new SessionBuilder($this->coordinator);
    }

    /**
     * Load a session by identifier.
     *
     * @throws SessionNotFound
     */
    public function session(string $sessionId): Session
    {
        return Session::query()->find($sessionId)
            ?? throw SessionNotFound::withId($sessionId);
    }

    /**
     * Load a session, or null when it does not exist.
     */
    public function findSession(string $sessionId): ?Session
    {
        return Session::query()->find($sessionId);
    }

    /**
     * Load a run by identifier, with its session eager loaded for authorization.
     *
     * @throws RunNotFound
     */
    public function run(string $runId): Run
    {
        return Run::query()->with('session')->find($runId)
            ?? throw RunNotFound::withId($runId);
    }

    /**
     * Load a run, or null when it does not exist.
     */
    public function findRun(string $runId): ?Run
    {
        return Run::query()->with('session')->find($runId);
    }

    /**
     * Every session belonging to a participant, newest first.
     *
     * @return Collection<int, Session>
     */
    public function sessionsFor(object $participant, int $limit = 50): Collection
    {
        return Session::query()
            ->forParticipant($participant)
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Every approval still awaiting a decision for a participant.
     *
     * @return Collection<int, Approval>
     */
    public function pendingApprovalsFor(object $participant): Collection
    {
        return Approval::query()
            ->pending()
            ->whereIn('session_id', Session::query()->forParticipant($participant)->select('id'))
            ->latest('requested_at')
            ->get();
    }

    /**
     * Apply the current session's permission mode to an agent's tool list.
     *
     * Call this from an agent's `tools()` method. Outside a harness run it is a
     * no-op, so the agent still works when prompted directly through Laravel AI.
     *
     * @param  iterable<int, object>  $tools
     * @return array<int, object>
     */
    public function policy(iterable $tools): array
    {
        return $this->container->make(PolicyAwareTools::class)->apply($tools);
    }

    /**
     * The driver registry, for registering custom drivers.
     */
    public function drivers(): DriverRegistry
    {
        return $this->drivers;
    }

    /**
     * Register a custom driver.
     *
     * @param  Closure(Container): Contracts\HarnessDriver  $creator
     */
    public function extend(string $name, Closure $creator): static
    {
        $this->drivers->extend($name, $creator);

        return $this;
    }

    /**
     * The coordinator, for advanced lifecycle operations.
     */
    public function coordinator(): RunCoordinator
    {
        return $this->coordinator;
    }

    /**
     * Swap the harness for a deterministic fake and return it.
     *
     * @param  array<array-key, mixed>  $responses
     */
    public function fake(array $responses = []): HarnessFake
    {
        $fake = new HarnessFake($this->container, $this->coordinator, $this->drivers, $responses);

        $fake->bind();

        return $fake;
    }
}
