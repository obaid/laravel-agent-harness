<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Runtime;

use AgentHarness\Laravel\Contracts\EventSerializer;

/**
 * Removes sensitive values before persistence, not merely before display.
 *
 * Raw secrets must never enter an event object, so redaction runs on the way
 * into the event store rather than on the way out of it.
 */
class Redactor
{
    public const PLACEHOLDER = '[REDACTED]';

    /**
     * @param  array<int, string>  $sensitiveKeys
     * @param  array<string, EventSerializer|class-string<EventSerializer>>  $toolSerializers
     */
    public function __construct(
        protected array $sensitiveKeys = [],
        protected array $toolSerializers = [],
        protected int $maxDepth = 12,
    ) {
        $this->sensitiveKeys = array_map(strtolower(...), $sensitiveKeys);
    }

    /**
     * Recursively replace configured sensitive keys throughout a payload.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload, int $depth = 0): array
    {
        if ($depth >= $this->maxDepth) {
            return ['_truncated' => true];
        }

        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $redacted[$key] = self::PLACEHOLDER;

                continue;
            }

            $redacted[$key] = is_array($value)
                ? $this->redact($value, $depth + 1)
                : $value;
        }

        return $redacted;
    }

    /**
     * Apply a tool-specific serializer, then redaction.
     *
     * Applications register serializers to keep all but approved fields out of
     * the event history entirely.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redactToolPayload(string $toolName, array $payload): array
    {
        $serializer = $this->serializerFor($toolName);

        if ($serializer instanceof EventSerializer) {
            $payload = $serializer->serialize($payload);
        }

        return $this->redact($payload);
    }

    /**
     * Determine whether a key name should be redacted.
     */
    public function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->sensitiveKeys as $sensitive) {
            if ($key === $sensitive || str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a payload still contains any configured sensitive key.
     *
     * Used by the checkpoint store to hold the "no secrets in checkpoints"
     * invariant, and by the test suite to assert it.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function containsSensitiveKeys(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitive($key) && $value !== self::PLACEHOLDER) {
                return true;
            }

            if (is_array($value) && $this->containsSensitiveKeys($value)) {
                return true;
            }
        }

        return false;
    }

    protected function serializerFor(string $toolName): ?EventSerializer
    {
        $serializer = $this->toolSerializers[$toolName] ?? null;

        if ($serializer === null) {
            return null;
        }

        return is_string($serializer) ? app($serializer) : $serializer;
    }
}
