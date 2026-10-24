<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Attachments;

final class StoredFile
{
    public function __construct(
        public readonly string $storageKey,
        public readonly ?string $previewKey,
        public readonly string $sha256,
        public readonly string $mime,
        public readonly int $sizeBytes,
        public readonly bool $isImage,
        public readonly string $filename,
        public readonly ?string $versionToken,
    ) {
    }
}
