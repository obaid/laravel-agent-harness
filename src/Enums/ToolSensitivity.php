<?php

declare(strict_types=1);

namespace Clutch\Laravel\Enums;

enum ToolSensitivity: string
{
    case ReadOnly = 'read_only';
    case Reversible = 'reversible';
    case Sensitive = 'sensitive';
    case Irreversible = 'irreversible';

    /**
     * Determine whether the tool is considered sensitive by default policy.
     */
    public function isSensitive(): bool
    {
        return $this === self::Sensitive || $this === self::Irreversible;
    }

    /**
     * Unknown tools are treated as sensitive.
     */
    public static function default(): self
    {
        return self::Sensitive;
    }
}
