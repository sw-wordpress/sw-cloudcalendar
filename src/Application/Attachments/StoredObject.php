<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Attachments;

final class StoredObject
{
    public function __construct(
        public readonly string $storageKey,
        public readonly int $sizeBytes,
        public readonly string $sha256,
    ) {
    }
}
