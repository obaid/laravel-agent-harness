<?php

declare(strict_types=1);

namespace Clutch\Laravel\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sortable, opaque, prefixed public identifiers.
 *
 * Identifiers are generated in application code before insert so they are
 * available to events and queue jobs within the same transaction.
 */
final class Id
{
    public const SESSION = 'ses';

    public const RUN = 'run';

    public const EVENT = 'evt';

    public const APPROVAL = 'apr';

    public const CHECKPOINT = 'chk';

    public const ARTIFACT = 'art';

    public const TOOL_CALL = 'tcl';

    /**
     * All identifier prefixes known to the harness.
     *
     * @var array<int, string>
     */
    public const PREFIXES = [
        self::SESSION, self::RUN, self::EVENT,
        self::APPROVAL, self::CHECKPOINT, self::ARTIFACT, self::TOOL_CALL,
    ];

    private function __construct() {}

    /**
     * Generate a new sortable identifier for the given prefix.
     */
    public static function make(string $prefix): string
    {
        if (! in_array($prefix, self::PREFIXES, true)) {
            throw new InvalidArgumentException("Unknown harness identifier prefix [{$prefix}].");
        }

        return $prefix.'_'.strtolower((string) Str::ulid());
    }

    public static function session(): string
    {
        return self::make(self::SESSION);
    }

    public static function run(): string
    {
        return self::make(self::RUN);
    }

    public static function event(): string
    {
        return self::make(self::EVENT);
    }

    public static function approval(): string
    {
        return self::make(self::APPROVAL);
    }

    public static function checkpoint(): string
    {
        return self::make(self::CHECKPOINT);
    }

    public static function artifact(): string
    {
        return self::make(self::ARTIFACT);
    }

    public static function toolCall(): string
    {
        return self::make(self::TOOL_CALL);
    }

    /**
     * Determine whether the given value is a well-formed identifier, optionally of a specific prefix.
     */
    public static function isValid(string $id, ?string $prefix = null): bool
    {
        $parts = explode('_', $id, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$actualPrefix, $ulid] = $parts;

        if (! in_array($actualPrefix, self::PREFIXES, true)) {
            return false;
        }

        if ($prefix !== null && $actualPrefix !== $prefix) {
            return false;
        }

        return Str::isUlid(strtoupper($ulid));
    }

    /**
     * Get the prefix portion of an identifier.
     */
    public static function prefix(string $id): ?string
    {
        $prefix = explode('_', $id, 2)[0];

        return in_array($prefix, self::PREFIXES, true) ? $prefix : null;
    }
}
