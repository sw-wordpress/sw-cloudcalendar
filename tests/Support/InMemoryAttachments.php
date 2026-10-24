<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Support;

use SW\Calendar\Application\Attachments\StoredFile;
use SW\Calendar\Application\Port\AttachmentStoreInterface;
use SW\Calendar\Application\Port\CacheInvalidatorInterface;
use SW\Calendar\Application\Port\ConnectionRepositoryInterface;
use SW\Calendar\Application\Port\JobQueueInterface;
use SW\Calendar\Application\Port\OccurrenceStoreInterface;
use SW\Calendar\Application\Port\ProviderItemStoreInterface;
use SW\Calendar\Application\Port\ProviderRegistryInterface;
use SW\Calendar\Application\Port\SourceRepositoryInterface;
use SW\Calendar\Application\Port\SyncRunStoreInterface;
use SW\Calendar\Application\Port\SyncStateStoreInterface;
use SW\Calendar\Application\Port\TransactionInterface;
use SW\Calendar\Application\Port\UserContextInterface;
use SW\Calendar\Application\Sync\SnapshotDiff;
use SW\Calendar\Application\Sync\SyncMetrics;
use SW\Calendar\Application\Sync\SyncStateRecord;
use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\ProviderEvent;
use SW\Calendar\Domain\Job\Job;
use SW\Calendar\Domain\Job\JobType;
use SW\Calendar\Domain\Provider\CalendarProviderInterface;
use SW\Calendar\Domain\Provider\SyncCursor;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Domain\Source\SourceStatus;

final class InMemoryAttachments implements AttachmentStoreInterface
{
    /** @var list<array{source: int, occurrence: int, ref: string, event: string}> */
    public array $reconciled = [];
    public int $revokedSources = 0;

    public function reconcile(int $sourceId, array $byOccurrence, \DateTimeImmutable $now, int $refreshHours): array
    {
        $pending = [];
        foreach ($byOccurrence as $occurrenceId => $data) {
            foreach ($data['attachments'] as $definition) {
                $this->reconciled[] = ['source' => $sourceId, 'occurrence' => $occurrenceId, 'ref' => $definition->refHash(), 'event' => $data['provider_event_id']];
                $pending[$definition->refHash()] = ['ref_hash' => $definition->refHash(), 'provider_event_id' => $data['provider_event_id'], 'version' => (string) $definition->versionToken];
            }
        }
        return ['pending' => array_values($pending), 'revoked' => 0];
    }

    public function definition(int $sourceId, string $refHash): ?array
    {
        return null;
    }

    public function forReference(int $sourceId, string $refHash): array
    {
        return [];
    }

    public function markStored(int $sourceId, string $refHash, StoredFile $file, PublicationState $state, \DateTimeImmutable $now): void
    {
    }

    public function markSkipped(int $sourceId, string $refHash, PublicationState $state, string $reason, \DateTimeImmutable $now): void
    {
    }

    public function markFailed(int $sourceId, string $refHash, string $error, bool $keepDelivering, \DateTimeImmutable $now): void
    {
    }

    public function revokeSource(int $sourceId): int
    {
        $this->revokedSources++;
        return 0;
    }

    public function unreferencedStorageKeys(\DateTimeImmutable $revokedBefore, int $limit): array
    {
        return [];
    }

    public function forgetStorageKey(string $storageKey): void
    {
    }

    public function storedBytes(?int $sourceId = null): int
    {
        return 0;
    }

    public function deleteSource(int $sourceId): void
    {
    }
}
