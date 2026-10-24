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

final class InMemoryOccurrences implements OccurrenceStoreInterface, Snapshottable
{
    /** @var array<int, array<string, array{id: int, hash: string, occurrence: Occurrence, generation: int}>> */
    public array $rows = [];
    private int $nextId = 1;

    public function replaceSnapshot(int $sourceId, array $occurrences, int $generation): SnapshotDiff
    {
        $created = $updated = $deleted = 0;
        $ids = [];
        $existing = $this->rows[$sourceId] ?? [];
        $next = [];
        foreach ($occurrences as $occurrence) {
            $hash = $occurrence->payloadHash();
            $row = $existing[$occurrence->occurrenceKey] ?? null;
            if ($row === null) {
                $row = ['id' => $this->nextId++, 'hash' => $hash, 'occurrence' => $occurrence, 'generation' => $generation];
                $created++;
            } elseif ($row['hash'] !== $hash) {
                $row = ['id' => $row['id'], 'hash' => $hash, 'occurrence' => $occurrence, 'generation' => $generation];
                $updated++;
            } else {
                $row['generation'] = $generation;
            }
            $next[$occurrence->occurrenceKey] = $row;
            $ids[$occurrence->occurrenceKey] = $row['id'];
        }
        $deletedIds = [];
        foreach ($existing as $key => $row) {
            if (!isset($next[$key])) {
                $deleted++;
                $deletedIds[] = $row['id'];
            }
        }
        $this->rows[$sourceId] = $next;
        return new SnapshotDiff($created, $updated, $deleted, $ids, $deletedIds);
    }

    /**
     * @return list<string>
     */
    public function titles(int $sourceId): array
    {
        $titles = array_map(static fn (array $r): string => $r['occurrence']->title . '@' . $r['occurrence']->startUtc->format('m-d'), $this->rows[$sourceId] ?? []);
        sort($titles);
        return array_values($titles);
    }

    public function deleteSource(int $sourceId): void
    {
        unset($this->rows[$sourceId]);
    }

    public function snapshot(): mixed
    {
        return [$this->rows, $this->nextId];
    }

    public function restore(mixed $state): void
    {
        [$this->rows, $this->nextId] = $state;
    }
}
