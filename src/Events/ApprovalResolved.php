<?php

declare(strict_types=1);

namespace Clutch\Laravel\Events;

use Clutch\Laravel\Models\Approval;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApprovalResolved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Approval $approval,
        public readonly bool $approved,
    ) {}
}
