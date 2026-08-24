<?php

declare(strict_types=1);

namespace AgentHarness\Laravel\Artifacts;

use AgentHarness\Laravel\Enums\ArtifactKind;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * A description of a durable output, before it is attached to a run.
 *
 * Contents always stay on a Laravel filesystem disk. This object carries only
 * the reference and the metadata the harness will persist.
 */
final class Artifact
{
    private ?string $name = null;

    private ?string $description = null;

    private ?string $mimeType = null;

    private ?ArtifactKind $kind = null;

    private ?int $sizeBytes = null;

    private ?string $sha256 = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private function __construct(
        public readonly string $disk,
        public readonly string $path,
        private ?string $pendingContents = null,
    ) {}

    /**
     * Reference a file that already exists on a disk.
     */
    public static function fromStorage(string $disk, string $path): self
    {
        return new self($disk, $path);
    }

    /**
     * Write contents to a disk as part of attaching the artifact.
     */
    public static function fromContents(string $contents, string $path, ?string $disk = null): self
    {
        return new self(
            $disk ?? (string) config('agent-harness.artifacts.disk', 'local'),
            $path,
            $contents,
        );
    }

    /**
     * Store a locally-readable file as an artifact.
     */
    public static function fromFile(string $localPath, ?string $path = null, ?string $disk = null): self
    {
        if (! is_readable($localPath)) {
            throw new InvalidArgumentException("The artifact source file [{$localPath}] is not readable.");
        }

        return self::fromContents(
            (string) file_get_contents($localPath),
            $path ?? basename($localPath),
            $disk,
        )->mimeType(mime_content_type($localPath) ?: null);
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function mimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    /**
     * Declare the size rather than having it measured.
     *
     * Useful for a large file already on a remote disk, where reading the whole
     * object back just to count its bytes would be wasteful.
     */
    public function size(int $bytes): self
    {
        $this->sizeBytes = $bytes;

        return $this;
    }

    /**
     * Declare a content hash computed elsewhere.
     */
    public function sha256(string $digest): self
    {
        $this->sha256 = $digest;

        return $this;
    }

    public function kind(ArtifactKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = [...$this->metadata, ...$metadata];

        return $this;
    }

    /**
     * Materialize pending contents onto the disk and compute integrity metadata.
     *
     * @return array<string, mixed>
     */
    public function persist(): array
    {
        $disk = Storage::disk($this->disk);

        if ($this->pendingContents !== null) {
            $disk->put($this->path, $this->pendingContents);
        }

        $contents = $this->pendingContents ?? $disk->get($this->path);

        return [
            'name' => $this->name ?? basename($this->path),
            'description' => $this->description,
            'kind' => ($this->kind ?? ArtifactKind::fromMimeType($this->resolveMimeType($disk)))->value,
            'mime_type' => $this->resolveMimeType($disk),
            'disk' => $this->disk,
            'path' => $this->path,
            'size_bytes' => $this->sizeBytes ?? ($contents !== null ? strlen($contents) : null),
            'sha256' => $this->sha256 ?? ($contents !== null ? hash('sha256', $contents) : null),
            'metadata' => $this->metadata,
        ];
    }

    private function resolveMimeType(mixed $disk): ?string
    {
        if ($this->mimeType !== null) {
            return $this->mimeType;
        }

        try {
            return $disk->mimeType($this->path) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
