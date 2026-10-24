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

final class InMemorySyncState implements SyncStateStoreInterface, Snapshottable
{
    /** @var array<int, array{cursor: SyncCursor, generation: int, failures: int, last_success: ?\DateTimeImmutable, error: ?string, next: ?\DateTimeImmutable, lock: ?\DateTimeImmutable}> */
    public array $state = [];

    private function row(int $sourceId): array
    {
        return $this->state[$sourceId] ??= ['cursor' => SyncCursor::empty(), 'generation' => 0, 'failures' => 0, 'last_success' => null, 'error' => null, 'next' => null, 'lock' => null];
    }

    public function acquireLock(int $sourceId, \DateTimeImmutable $now, \DateTimeImmutable $until): bool
    {
        $row = $this->row($sourceId);
        if ($row['lock'] !== null && $row['lock'] > $now) {
            return false;
        }
        $this->state[$sourceId]['lock'] = $until;
        return true;
    }

    public function releaseLock(int $sourceId): void
    {
        $this->row($sourceId);
        $this->state[$sourceId]['lock'] = null;
    }

    public function load(int $sourceId): SyncStateRecord
    {
        $row = $this->row($sourceId);
        return new SyncStateRecord($row['cursor'], $row['generation'], $row['failures'], $row['last_success'], $row['error'], $row['next']);
    }

    public function saveSuccess(int $sourceId, SyncCursor $cursor, SyncWindow $window, int $generation, string $mode, \DateTimeImmutable $now, \DateTimeImmutable $nextAttempt): void
    {
        $this->row($sourceId);
        $this->state[$sourceId] = array_merge($this->state[$sourceId], ['cursor' => $cursor, 'generation' => $generation, 'failures' => 0, 'last_success' => $now, 'error' => null, 'next' => $nextAttempt]);
    }

    public function saveFailure(int $sourceId, string $errorCode, int $failures, \DateTimeImmutable $nextAttempt): void
    {
        $this->row($sourceId);
        $this->state[$sourceId] = array_merge($this->state[$sourceId], ['failures' => $failures, 'error' => $errorCode, 'next' => $nextAttempt]);
    }

    public function resetCursor(int $sourceId): void
    {
        $this->row($sourceId);
        $this->state[$sourceId]['cursor'] = SyncCursor::empty();
    }

    public function due(\DateTimeImmutable $now, int $limit): array
    {
        $ids = [];
        foreach ($this->state as $id => $row) {
            if ($row['next'] === null || $row['next'] <= $now) {
                $ids[] = $id;
            }
        }
        return array_slice($ids, 0, $limit);
    }

    public function snapshot(): mixed
    {
        return $this->state;
    }

    public function restore(mixed $state): void
    {
        $this->state = $state;
    }
}
