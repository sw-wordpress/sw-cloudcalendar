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

final class InMemoryItems implements ProviderItemStoreInterface, Snapshottable
{
    /** @var array<int, array<string, ProviderEvent>> */
    public array $items = [];
    /** @var array<int, array{source: int, items: array<string, ProviderEvent>, removed: list<string>}> */
    public array $staging = [];

    public function stage(int $runId, int $sourceId, array $items, array $removedItemIds): void
    {
        $this->staging[$runId] ??= ['source' => $sourceId, 'items' => [], 'removed' => []];
        foreach ($items as $item) {
            // Round trip through the storage format like the database implementation.
            $this->staging[$runId]['items'][$item->itemId] = ProviderEvent::fromArray(json_decode((string) json_encode($item->toArray()), true));
        }
        $this->staging[$runId]['removed'] = array_merge($this->staging[$runId]['removed'], $removedItemIds);
    }

    public function discardStaging(int $runId): void
    {
        unset($this->staging[$runId]);
    }

    public function applyStaging(int $runId, int $sourceId, bool $fullSnapshot): void
    {
        $stage = $this->staging[$runId] ?? ['items' => [], 'removed' => []];
        if ($fullSnapshot) {
            $this->items[$sourceId] = [];
        }
        foreach ($stage['items'] as $id => $item) {
            $this->items[$sourceId][$id] = $item;
        }
        foreach ($stage['removed'] as $id) {
            unset($this->items[$sourceId][$id]);
        }
        unset($this->staging[$runId]);
    }

    public function loadItems(int $sourceId): iterable
    {
        return array_values($this->items[$sourceId] ?? []);
    }

    public function deleteSource(int $sourceId): void
    {
        unset($this->items[$sourceId]);
    }

    public function snapshot(): mixed
    {
        return [$this->items, $this->staging];
    }

    public function restore(mixed $state): void
    {
        [$this->items, $this->staging] = $state;
    }
}
