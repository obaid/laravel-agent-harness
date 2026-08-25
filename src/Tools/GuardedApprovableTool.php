<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tools;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;

/**
 * A guarded tool that is still approvable.
 *
 * Laravel AI decides whether a call pauses by checking `instanceof Approvable`,
 * so this exists purely to carry that interface across the wrapper. Every
 * approval decision is still the inner tool's to make.
 */
class GuardedApprovableTool extends GuardedTool implements Approvable
{
    public function requireApproval(?string $reason = null): static
    {
        $this->approvable()->requireApproval($reason);

        return $this;
    }

    public function withoutApproval(): static
    {
        $this->approvable()->withoutApproval();

        return $this;
    }

    public function shouldRequestApproval(Request $request): ?Approval
    {
        return $this->approvable()->shouldRequestApproval($request);
    }

    /**
     * The inner tool, which wrap() guarantees is approvable.
     */
    protected function approvable(): Approvable
    {
        return $this->tool instanceof Approvable
            ? $this->tool
            : throw new \LogicException(
                'GuardedApprovableTool wraps a tool that is not approvable. Build it through '.
                'GuardedTool::wrap(), which picks the right wrapper.'
            );
    }
}
