<?php

declare(strict_types=1);

namespace Clutch\Laravel\Models\Concerns;

use Clutch\Laravel\Support\Id;

/**
 * Assigns a prefixed, sortable identifier before insert so the value is
 * available to events and queue jobs within the same transaction.
 */
trait HasHarnessId
{
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    protected static function bootHasHarnessId(): void
    {
        static::creating(function ($model): void {
            if (blank($model->getKey())) {
                $model->setAttribute($model->getKeyName(), Id::make($model->idPrefix()));
            }
        });
    }

    /**
     * The identifier prefix for this model.
     */
    abstract public function idPrefix(): string;
}
