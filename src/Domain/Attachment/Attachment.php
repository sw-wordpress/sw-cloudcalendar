<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Attachment;

/**
 * Local record of a provider attachment (SPECIFICATIONS.md §3.6). The storage key never leaves the server.
 */
final class Attachment
{
    public function __construct(
        public readonly int $id,
        public readonly int $sourceId,
        public readonly int $occurrenceId,
        public readonly string $providerRefHash,
        public readonly string $providerId,
        public readonly string $filename,
        public readonly string $mime,
        public readonly int $sizeBytes,
        public readonly ?string $versionToken,
        public readonly ?string $sha256,
        public readonly ?string $storageKey,
        public readonly ?string $previewKey,
        public readonly bool $isImage,
        public readonly PublicationState $publicationState,
        public readonly SyncState $syncState,
        public readonly ?\DateTimeImmutable $lastCheckedAt = null,
        public readonly ?string $lastError = null,
    ) {
    }

    public function isDeliverable(): bool
    {
        return $this->syncState->isDeliverable() && $this->storageKey !== null;
    }
}
