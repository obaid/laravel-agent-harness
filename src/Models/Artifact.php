<?php

declare(strict_types=1);

namespace Clutch\Laravel\Models;

use Clutch\Laravel\Enums\ArtifactKind;
use Clutch\Laravel\Exceptions\ArtifactUnavailable;
use Clutch\Laravel\Models\Concerns\HasHarnessId;
use Clutch\Laravel\Support\Id;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A durable output produced by a run.
 *
 * Contents stay on the configured filesystem disk; this record holds metadata,
 * ownership, integrity information, and the storage reference.
 *
 * @property string $id
 * @property string $session_id
 * @property string|null $run_id
 * @property string $name
 * @property string|null $description
 * @property ArtifactKind $kind
 * @property string|null $mime_type
 * @property string $disk
 * @property string $path
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Session|null $session
 */
class Artifact extends Model
{
    use HasHarnessId;

    protected $table = 'clutch_artifacts';

    protected $guarded = [];

    public function idPrefix(): string
    {
        return Id::ARTIFACT;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ArtifactKind::class,
            'metadata' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Session, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /**
     * The disk this artifact lives on.
     */
    public function storage(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * Determine whether the bytes are still present.
     */
    public function exists(): bool
    {
        return $this->storage()->exists($this->path);
    }

    /**
     * Read the artifact's contents.
     *
     * @throws ArtifactUnavailable
     */
    public function contents(): string
    {
        $contents = $this->storage()->get($this->path);

        if ($contents === null) {
            throw ArtifactUnavailable::missing($this->id);
        }

        return $contents;
    }

    /**
     * Open a read stream over the artifact.
     *
     * @return resource
     *
     * @throws ArtifactUnavailable
     */
    public function readStream()
    {
        $stream = $this->storage()->readStream($this->path);

        if (! is_resource($stream)) {
            throw ArtifactUnavailable::missing($this->id);
        }

        return $stream;
    }

    /**
     * Generate a temporary URL, when the disk supports one.
     */
    public function temporaryUrl(?DateTimeInterface $expiresAt = null): ?string
    {
        $disk = $this->storage();

        if (! method_exists($disk, 'temporaryUrl')) {
            return null;
        }

        try {
            return $disk->temporaryUrl($this->path, $expiresAt ?? now()->addMinutes(15));
        } catch (\RuntimeException) {
            // The driver does not support temporary URLs (e.g. the local disk).
            return null;
        }
    }

    /**
     * Verify the stored bytes still match the recorded hash.
     */
    public function hasIntactContents(): bool
    {
        if ($this->sha256 === null) {
            return true;
        }

        return hash('sha256', $this->contents()) === $this->sha256;
    }
}
