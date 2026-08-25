<?php

declare(strict_types=1);

namespace Clutch\Laravel\Policies;

/**
 * What the policy engine decided about one tool call.
 */
final readonly class PolicyDecision
{
    public const ALLOW = 'allow';

    public const REQUIRE_APPROVAL = 'require_approval';

    public const DENY = 'deny';

    public function __construct(
        public string $outcome,
        public ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    public static function requireApproval(?string $reason = null): self
    {
        return new self(self::REQUIRE_APPROVAL, $reason);
    }

    public static function deny(?string $reason = null): self
    {
        return new self(self::DENY, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->outcome === self::ALLOW;
    }

    public function requiresApproval(): bool
    {
        return $this->outcome === self::REQUIRE_APPROVAL;
    }

    public function isDenied(): bool
    {
        return $this->outcome === self::DENY;
    }

    /**
     * Combine two decisions, keeping the more restrictive one.
     */
    public function mergeRestrictive(self $other): self
    {
        $rank = fn (self $d): int => match ($d->outcome) {
            self::ALLOW => 0,
            self::REQUIRE_APPROVAL => 1,
            default => 2,
        };

        return $rank($other) > $rank($this) ? $other : $this;
    }
}
