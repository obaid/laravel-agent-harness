<?php

declare(strict_types=1);

namespace Clutch\Laravel\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }
}
