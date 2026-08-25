<?php

declare(strict_types=1);

namespace Clutch\Laravel\Events;

use Clutch\Laravel\Models\Approval;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a run pauses for a human decision.
 *
 * Applications hang notifications off this: email the approver, post to Slack,
 * open a task.
 */
class ApprovalRequested
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Approval $approval) {}
}
