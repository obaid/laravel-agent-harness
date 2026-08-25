<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use Clutch\Laravel\Data\PendingApproval;
use RuntimeException;

/**
 * An agent a workflow prompted stopped to ask for approval.
 *
 * The workflow cannot simply carry on: the agent has not done the work yet.
 * So the pause is propagated outward, the workflow's own run parks with the
 * agent's tool call surfaced on it, and the decision reaches the agent when
 * the workflow resumes.
 *
 * Crucially the step this happened inside is never recorded, so re-entry runs
 * it again rather than treating an unfinished prompt as a finished one.
 *
 * @internal
 */
final class AgentPaused extends RuntimeException
{
    /**
     * @param  array<int, PendingApproval>  $approvals
     */
    public function __construct(
        public readonly string $agentClass,
        public readonly string $agentRunId,
        public readonly array $approvals,
    ) {
        parent::__construct(sprintf('[%s] is waiting for approval.', $agentClass));
    }
}
