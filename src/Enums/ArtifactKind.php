<?php

declare(strict_types=1);

namespace Clutch\Laravel\Enums;

enum ArtifactKind: string
{
    case Document = 'document';
    case Image = 'image';
    case Data = 'data';
    case Archive = 'archive';
    case Code = 'code';
    case Other = 'other';

    /**
     * Infer a kind from a MIME type.
     */
    public static function fromMimeType(?string $mimeType): self
    {
        return match (true) {
            $mimeType === null => self::Other,
            str_starts_with($mimeType, 'image/') => self::Image,
            str_starts_with($mimeType, 'text/csv'), $mimeType === 'application/json' => self::Data,
            str_starts_with($mimeType, 'text/'), $mimeType === 'application/pdf' => self::Document,
            in_array($mimeType, ['application/zip', 'application/gzip', 'application/x-tar'], true) => self::Archive,
            default => self::Other,
        };
    }
}
