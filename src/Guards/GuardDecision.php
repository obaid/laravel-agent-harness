<?php

declare(strict_types=1);

namespace Clutch\Laravel\Guards;

/**
 * What a guard decided about one tool call.
 */
final readonly class GuardDecision
{
    public const PROCEED = 'proceed';

    public const REMIND = 'remind';

    public const BLOCK = 'block';

    public function __construct(
        public string $outcome,
        public ?string $message = null,
    ) {}

    public static function proceed(): self
    {
        return new self(self::PROCEED);
    }

    /**
     * Let the call run, but tell the model it is going in circles.
     */
    public static function remind(string $message): self
    {
        return new self(self::REMIND, $message);
    }

    /**
     * Refuse the call and give the model the reason as its result.
     */
    public static function block(string $message): self
    {
        return new self(self::BLOCK, $message);
    }

    public function allowsExecution(): bool
    {
        return $this->outcome !== self::BLOCK;
    }

    public function hasMessage(): bool
    {
        return $this->message !== null;
    }

    public function isBlocked(): bool
    {
        return $this->outcome === self::BLOCK;
    }

    public function isReminder(): bool
    {
        return $this->outcome === self::REMIND;
    }
}
