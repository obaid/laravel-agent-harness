<?php

declare(strict_types=1);

namespace Clutch\Laravel\Enums;

enum PermissionMode: string
{
    /** Only explicitly allowed tools may run. */
    case DenyByDefault = 'deny_by_default';

    /** Safe tools run; sensitive tools require approval. */
    case ApproveSensitive = 'approve_sensitive';

    /** Every state-changing tool requires approval. */
    case ApproveAll = 'approve_all';

    /** Tools run without harness approval; intended for trusted environments. */
    case AllowAll = 'allow_all';

    /**
     * Determine whether a tool of the given sensitivity requires approval under this mode.
     */
    public function requiresApprovalFor(ToolSensitivity $sensitivity): bool
    {
        return match ($this) {
            self::AllowAll => false,
            self::DenyByDefault => $sensitivity !== ToolSensitivity::ReadOnly,
            self::ApproveAll => $sensitivity !== ToolSensitivity::ReadOnly,
            self::ApproveSensitive => $sensitivity->isSensitive(),
        };
    }
}
