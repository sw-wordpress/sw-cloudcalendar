<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Sync;

final class SnapshotDiff
{
    /**
     * @param array<string, int> $idsByKey  Occurrence key => local id for every occurrence of the snapshot.
     * @param list<int>          $deletedIds
     */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $deleted,
        public readonly array $idsByKey,
        public readonly array $deletedIds = [],
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->created + $this->updated + $this->deleted > 0;
    }
}
