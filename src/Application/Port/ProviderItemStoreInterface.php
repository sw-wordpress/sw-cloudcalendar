<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Domain\Event\ProviderEvent;

/**
 * Local mirror of provider items (series masters, overrides, instances) plus an isolated staging area per run.
 * Staged data becomes visible only through applyStaging() inside the commit transaction.
 */
interface ProviderItemStoreInterface
{
    /**
     * @param list<ProviderEvent> $items
     * @param list<string>        $removedItemIds
     */
    public function stage(int $runId, int $sourceId, array $items, array $removedItemIds): void;

    public function discardStaging(int $runId): void;

    /**
     * Applies the staged changes. A full snapshot replaces all items of the source; a delta upserts and removes.
     */
    public function applyStaging(int $runId, int $sourceId, bool $fullSnapshot): void;

    /**
     * @return iterable<ProviderEvent>
     */
    public function loadItems(int $sourceId): iterable;

    public function deleteSource(int $sourceId): void;
}
