<?php

declare(strict_types=1);

namespace Clutch\Laravel\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The truthful declaration of what a harness driver can do.
 *
 * The harness validates a requested operation against these flags before
 * execution, so an unsupported feature fails explicitly rather than degrading.
 *
 * @implements Arrayable<string, bool>
 */
final readonly class DriverCapabilities implements Arrayable
{
    public function __construct(
        public bool $streaming = false,
        public bool $hostTools = false,
        public bool $nativeTools = false,
        public bool $approvals = false,
        public bool $structuredOutput = false,
        public bool $sessionResume = false,
        public bool $inFlightContinuation = false,
        public bool $manualCompaction = false,
        public bool $sandboxRequired = false,
        public bool $workspaceRequired = false,
    ) {}

    /**
     * Determine whether the named capability is supported.
     */
    public function supports(string $capability): bool
    {
        return match ($capability) {
            'streaming' => $this->streaming,
            'host_tools' => $this->hostTools,
            'native_tools' => $this->nativeTools,
            'approvals' => $this->approvals,
            'structured_output' => $this->structuredOutput,
            'session_resume' => $this->sessionResume,
            'in_flight_continuation' => $this->inFlightContinuation,
            'manual_compaction' => $this->manualCompaction,
            default => false,
        };
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'streaming' => $this->streaming,
            'host_tools' => $this->hostTools,
            'native_tools' => $this->nativeTools,
            'approvals' => $this->approvals,
            'structured_output' => $this->structuredOutput,
            'session_resume' => $this->sessionResume,
            'in_flight_continuation' => $this->inFlightContinuation,
            'manual_compaction' => $this->manualCompaction,
            'sandbox_required' => $this->sandboxRequired,
            'workspace_required' => $this->workspaceRequired,
        ];
    }
}
