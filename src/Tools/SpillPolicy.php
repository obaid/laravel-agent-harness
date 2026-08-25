<?php

declare(strict_types=1);

namespace Clutch\Laravel\Tools;

use Clutch\Laravel\Artifacts\Artifact;
use Clutch\Laravel\Artifacts\ArtifactRegistrar;
use Clutch\Laravel\Enums\ArtifactKind;

/**
 * Keeps an oversized tool result out of the conversation.
 *
 * A tool that returns a 400KB page dump poisons every later step: the model
 * pays for it on each turn, and the context window fills with text nobody
 * reads. This writes the full output to an artifact and hands the model a
 * bounded preview plus the identifier it needs to ask for the rest.
 *
 * The model still gets the beginning of the output, which is usually where the
 * answer is, and the full text stays available to the application.
 */
class SpillPolicy
{
    public function __construct(
        /** Results longer than this are spilled. */
        protected int $thresholdBytes = 8192,
        /** How much of the output the model keeps inline. */
        protected int $previewBytes = 1024,
        protected bool $enabled = true,
    ) {}

    /**
     * Determine whether a result is large enough to spill.
     */
    public function shouldSpill(string $result): bool
    {
        return $this->enabled && strlen($result) > $this->thresholdBytes;
    }

    /**
     * Spill a result, returning what the model should see instead.
     *
     * The artifact is attached to the run, so the full text is downloadable
     * through the ordinary artifact route and pruned by the ordinary retention
     * window. Nothing new to operate.
     */
    public function spill(
        ArtifactRegistrar $artifacts,
        string $toolName,
        string $toolCallId,
        string $result,
    ): SpilledResult {
        $size = strlen($result);

        $artifact = $artifacts->add(
            Artifact::fromContents($result, $this->pathFor($toolName, $toolCallId))
                ->name("Output of {$toolName}")
                ->description("Full result of the {$toolName} call {$toolCallId}.")
                ->kind(ArtifactKind::Data)
                ->mimeType('text/plain')
                ->metadata([
                    'tool' => $toolName,
                    'tool_call_id' => $toolCallId,
                    'spilled' => true,
                    'original_size_bytes' => $size,
                ])
        );

        return new SpilledResult(
            artifactId: $artifact->id,
            preview: $this->preview($result),
            originalSizeBytes: $size,
            toolName: $toolName,
        );
    }

    /**
     * Cut the preview on a line boundary where possible, so it does not end
     * halfway through a word the model then has to guess at.
     */
    protected function preview(string $result): string
    {
        if (strlen($result) <= $this->previewBytes) {
            return $result;
        }

        $slice = substr($result, 0, $this->previewBytes);
        $lastNewline = strrpos($slice, "\n");

        // Only honour the line break if it is not so early that the preview
        // becomes useless.
        if ($lastNewline !== false && $lastNewline > (int) ($this->previewBytes * 0.5)) {
            return substr($slice, 0, $lastNewline);
        }

        return $slice;
    }

    protected function pathFor(string $toolName, string $toolCallId): string
    {
        return 'spill/'.preg_replace('/[^a-z0-9_-]/i', '-', $toolName).'/'.$toolCallId.'.txt';
    }

    public function thresholdBytes(): int
    {
        return $this->thresholdBytes;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
