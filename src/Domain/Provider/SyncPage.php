<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

use SW\Calendar\Domain\Event\ProviderEvent;

/**
 * One page of provider changes. `fullSnapshot` pages replace the whole local item set once ALL pages were fetched;
 * delta pages contain upserts and removals only.
 */
final class SyncPage
{
    /**
     * @param list<ProviderEvent> $items
     * @param list<string>        $removedItemIds
     */
    public function __construct(
        public readonly array $items,
        public readonly array $removedItemIds,
        public readonly bool $fullSnapshot,
        /** Continuation for the next page; null on the last page. */
        public readonly ?SyncCursor $nextPage,
        /** Cursor to persist after a successful commit; only set on the last page. */
        public readonly ?SyncCursor $finalCursor,
        /** Provider reported "not modified" (ICS 304): the existing item set stays valid. */
        public readonly bool $notModified = false,
    ) {
    }

    public function isLast(): bool
    {
        return $this->nextPage === null;
    }
}
