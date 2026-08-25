<?php

declare(strict_types=1);

namespace Clutch\Laravel\Workflows;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The scratch space a workflow run stages inputs into and collects outputs from.
 *
 * It is an ordinary Laravel disk scoped to one run rather than a container, so
 * staging works with no sandbox provisioned at all. A provider that does give
 * real isolation mounts this path instead of inventing its own.
 */
final class WorkflowWorkspace
{
    protected Filesystem $disk;

    public function __construct(
        public readonly string $sessionId,
        ?string $disk = null,
    ) {
        $this->disk = Storage::disk($disk ?? (string) config('clutch.artifacts.disk', 'local'));
    }

    /**
     * The prefix every file in this workspace lives under.
     */
    public function prefix(): string
    {
        return 'clutch/workflows/'.$this->sessionId;
    }

    /**
     * The absolute path, when the disk is local enough to have one.
     */
    public function path(string $relative = ''): ?string
    {
        try {
            return $this->disk->path(ltrim($this->prefix().'/'.$relative, '/'));
        } catch (\Throwable) {
            // Remote disks have no local path. Callers must fall back to get().
            return null;
        }
    }

    public function put(string $relative, string $contents): void
    {
        $this->disk->put($this->qualify($relative), $contents);
    }

    public function get(string $relative): ?string
    {
        $path = $this->qualify($relative);

        return $this->disk->exists($path) ? ($this->disk->get($path) ?? null) : null;
    }

    public function exists(string $relative): bool
    {
        return $this->disk->exists($this->qualify($relative));
    }

    /**
     * Every file in the workspace, as paths relative to it.
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        $prefix = $this->prefix().'/';

        return array_values(array_map(
            fn (string $path): string => str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path,
            $this->disk->allFiles($this->prefix()),
        ));
    }

    /**
     * Relative paths matching a glob such as `reports/*.md`.
     *
     * @return array<int, string>
     */
    public function match(string $pattern): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (string $path): bool => fnmatch($pattern, $path, FNM_PATHNAME) || fnmatch($pattern, $path),
        ));
    }

    /**
     * Remove everything the run staged. Artifacts are already persisted
     * elsewhere, so this only discards scratch.
     */
    public function discard(): void
    {
        $this->disk->deleteDirectory($this->prefix());
    }

    protected function qualify(string $relative): string
    {
        return $this->prefix().'/'.ltrim($relative, '/');
    }
}
