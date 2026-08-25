<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tools;

use Illuminate\Contracts\Support\Arrayable;
use Stringable;

/**
 * What the model sees in place of an oversized tool result.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class SpilledResult implements Arrayable, Stringable
{
    public function __construct(
        public string $artifactId,
        public string $preview,
        public int $originalSizeBytes,
        public string $toolName,
    ) {}

    /**
     * The text handed back to the model.
     *
     * It says plainly that the output was truncated and how to get the rest,
     * because a model given a silent truncation will answer from the fragment
     * as though it were the whole thing.
     */
    public function __toString(): string
    {
        $kb = number_format($this->originalSizeBytes / 1024, 1);

        return <<<TEXT
        {$this->preview}

        [Output truncated. The full {$kb} KB result is stored as artifact {$this->artifactId}.
        Ask for that artifact if you need more than the excerpt above.]
        TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifact_id' => $this->artifactId,
            'tool' => $this->toolName,
            'original_size_bytes' => $this->originalSizeBytes,
            'preview' => $this->preview,
            'spilled' => true,
        ];
    }
}
