<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

/**
 * Everything a workflow run needs to pick up where it stopped.
 *
 * This is the payload of the driver session and, once persisted, of the
 * checkpoint. It is deliberately plain: it round-trips through JSON without
 * losing anything, because it has to survive the process that wrote it.
 */
final class WorkflowState
{
    /**
     * @param  class-string<Workflow>  $workflow
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $steps  completed step results, keyed by step name
     * @param  array<string, string>  $sessions  child session ids, keyed by agent class
     * @param  array<string, string>  $artifacts  artifact ids, keyed by name
     * @param  array<string, mixed>|null  $pause
     * @param  array<string, mixed>  $resumeInput
     */
    public function __construct(
        public string $workflow,
        public array $payload = [],
        public array $steps = [],
        public array $sessions = [],
        public array $artifacts = [],
        public ?array $pause = null,
        public array $resumeInput = [],
        public bool $staged = false,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        return new self(
            workflow: (string) ($state['workflow'] ?? ''),
            payload: (array) ($state['payload'] ?? []),
            steps: (array) ($state['steps'] ?? []),
            sessions: (array) ($state['sessions'] ?? []),
            artifacts: (array) ($state['artifacts'] ?? []),
            pause: isset($state['pause']) && is_array($state['pause']) ? $state['pause'] : null,
            resumeInput: (array) ($state['resume_input'] ?? []),
            staged: (bool) ($state['staged'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workflow' => $this->workflow,
            'payload' => $this->payload,
            'steps' => $this->steps,
            'sessions' => $this->sessions,
            'artifacts' => $this->artifacts,
            'pause' => $this->pause,
            'resume_input' => $this->resumeInput,
            'staged' => $this->staged,
        ];
    }

    /**
     * Determine whether a step has already run to completion.
     */
    public function hasStep(string $name): bool
    {
        return array_key_exists($name, $this->steps);
    }

    /**
     * The stored result of a completed step.
     */
    public function step(string $name): mixed
    {
        return $this->steps[$name]['value'] ?? null;
    }

    /**
     * Record a completed step. Values must survive a JSON round trip.
     */
    public function recordStep(string $name, mixed $value): void
    {
        $this->steps[$name] = ['value' => $value];
    }

    /**
     * The names of every step that has completed, in the order they landed.
     *
     * @return array<int, string>
     */
    public function completedSteps(): array
    {
        return array_keys($this->steps);
    }
}
