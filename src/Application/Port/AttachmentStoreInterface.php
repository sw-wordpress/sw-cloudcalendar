<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Application\Attachments\StoredFile;
use SW\Calendar\Domain\Attachment\Attachment;
use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\Provider\ProviderAttachmentDefinition;

/**
 * Attachment records. All rows sharing one provider reference (e.g. the instances of a recurring series) share one
 * stored file and are updated together.
 */
interface AttachmentStoreInterface
{
    /**
     * Upserts the attachment rows of the committed occurrences and revokes rows that are no longer referenced.
     *
     * @param array<int, array{provider_event_id: string, attachments: list<ProviderAttachmentDefinition>}> $byOccurrence
     * @return array{pending: list<array{ref_hash: string, provider_event_id: string, version: string}>, revoked: int}
     */
    public function reconcile(int $sourceId, array $byOccurrence, \DateTimeImmutable $now, int $refreshHours): array;

    /**
     * Definition fields of a reference as last delivered by the provider.
     *
     * @return array{kind: string, reference: string, filename: string, mime: string, size: int, version: ?string, provider_event_id: string}|null
     */
    public function definition(int $sourceId, string $refHash): ?array;

    /**
     * @return list<Attachment>
     */
    public function forReference(int $sourceId, string $refHash): array;

    public function markStored(int $sourceId, string $refHash, StoredFile $file, PublicationState $state, \DateTimeImmutable $now): void;

    /** Not synchronised because of the policy; any previously stored file is no longer deliverable. */
    public function markSkipped(int $sourceId, string $refHash, PublicationState $state, string $reason, \DateTimeImmutable $now): void;

    /**
     * @param bool $keepDelivering Keep an already stored file deliverable (only if its publication is still valid).
     */
    public function markFailed(int $sourceId, string $refHash, string $error, bool $keepDelivering, \DateTimeImmutable $now): void;

    /** Immediately blocks delivery of all files of a source (policy change, deactivation). */
    public function revokeSource(int $sourceId): int;

    /**
     * Storage keys that are no longer referenced by any deliverable or pending row.
     *
     * @return list<string>
     */
    public function unreferencedStorageKeys(\DateTimeImmutable $revokedBefore, int $limit): array;

    public function forgetStorageKey(string $storageKey): void;

    public function storedBytes(?int $sourceId = null): int;

    public function deleteSource(int $sourceId): void;
}
