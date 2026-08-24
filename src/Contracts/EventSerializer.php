<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Contracts;

/**
 * Application-supplied control over what a tool's arguments and results
 * contribute to the persisted event history.
 *
 * Registered per tool name, this runs before persistence, so a serializer that
 * retains only approved fields keeps everything else out of the database.
 */
interface EventSerializer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function serialize(array $payload): array;
}
