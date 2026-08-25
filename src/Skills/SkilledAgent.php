<?php

declare(strict_types=1);

namespace Clutch\Laravel\Skills;

use Laravel\Ai\Contracts\Agent;

/**
 * Wraps an agent so its instructions advertise the session's skills.
 *
 * Laravel AI has no notion of skills, and the one hook every agent exposes is
 * its instructions. Rather than asking applications to paste a catalogue into
 * every agent by hand, the driver wraps the resolved agent in this and the
 * catalogue is appended for the turn.
 *
 * The wrapper is deliberately thin: every other call passes straight through,
 * so an agent keeps its own tools, conversation, structured output, and
 * provider configuration.
 */
final class SkilledAgent implements Agent
{
    public function __construct(
        private readonly Agent $agent,
        private readonly SkillRegistry $skills,
    ) {}

    /**
     * Wrap an agent, unless there is nothing to advertise.
     */
    public static function wrap(Agent $agent, SkillRegistry $skills): Agent
    {
        return $skills->isEmpty() ? $agent : new self($agent, $skills);
    }

    public function instructions(): string
    {
        return trim((string) $this->agent->instructions())."\n\n".$this->skills->catalogue();
    }

    /**
     * The agent this wraps, for a driver that needs the real class.
     */
    public function inner(): Agent
    {
        return $this->agent;
    }

    /**
     * Pass everything else through untouched.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->agent->{$method}(...$arguments);
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @param  array<string, string|null>|string|null  $provider
     */
    public function prompt(
        \Laravel\Ai\Approvals\Decisions|string $prompt,
        array $attachments = [],
        \Laravel\Ai\Enums\Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Laravel\Ai\Responses\AgentResponse {
        return $this->agent->prompt($prompt, $attachments, $provider, $model, $timeout);
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @param  array<string, string|null>|string|null  $provider
     */
    public function stream(
        \Laravel\Ai\Approvals\Decisions|string $prompt,
        array $attachments = [],
        \Laravel\Ai\Enums\Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Laravel\Ai\Responses\StreamableAgentResponse {
        return $this->agent->stream($prompt, $attachments, $provider, $model, $timeout);
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @param  array<string, string|null>|string|null  $provider
     */
    public function queue(
        \Laravel\Ai\Approvals\Decisions|string $prompt,
        array $attachments = [],
        \Laravel\Ai\Enums\Lab|array|string|null $provider = null,
        ?string $model = null,
    ): \Laravel\Ai\Responses\QueuedAgentResponse {
        return $this->agent->queue($prompt, $attachments, $provider, $model);
    }

    /**
     * @param  \Illuminate\Broadcasting\Channel|array<int, \Illuminate\Broadcasting\Channel>  $channels
     * @param  array<int, mixed>  $attachments
     * @param  array<string, string|null>|string|null  $provider
     */
    public function broadcast(
        \Laravel\Ai\Approvals\Decisions|string $prompt,
        \Illuminate\Broadcasting\Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        \Laravel\Ai\Enums\Lab|array|string|null $provider = null,
        ?string $model = null,
    ): \Laravel\Ai\Responses\StreamableAgentResponse {
        return $this->agent->broadcast($prompt, $channels, $attachments, $now, $provider, $model);
    }

    /**
     * @param  \Illuminate\Broadcasting\Channel|array<int, \Illuminate\Broadcasting\Channel>  $channels
     * @param  array<int, mixed>  $attachments
     * @param  array<string, string|null>|string|null  $provider
     */
    public function broadcastNow(
        \Laravel\Ai\Approvals\Decisions|string $prompt,
        \Illuminate\Broadcasting\Channel|array $channels,
        array $attachments = [],
        \Laravel\Ai\Enums\Lab|array|string|null $provider = null,
        ?string $model = null,
    ): \Laravel\Ai\Responses\StreamableAgentResponse {
        return $this->agent->broadcastNow($prompt, $channels, $attachments, $provider, $model);
    }

    /**
     * @param  \Illuminate\Broadcasting\Channel|array<int, \Illuminate\Broadcasting\Channel>  $channels
     * @param  array<int, mixed>  $attachments
     * @param  array<string, string|null>|string|null  $provider
     */
    public function broadcastOnQueue(
        \Laravel\Ai\Approvals\Decisions|string $prompt,
        \Illuminate\Broadcasting\Channel|array $channels,
        array $attachments = [],
        \Laravel\Ai\Enums\Lab|array|string|null $provider = null,
        ?string $model = null,
    ): \Laravel\Ai\Responses\QueuedAgentResponse {
        return $this->agent->broadcastOnQueue($prompt, $channels, $attachments, $provider, $model);
    }
}
