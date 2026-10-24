<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Application\Sync\SnapshotDiff;
use SW\Calendar\Domain\Event\Occurrence;

interface OccurrenceStoreInterface
{
    /**
     * Generation based swap: upserts every occurrence with `seen_generation = $generation` (writes only when the
     * payload hash changed) and deletes the source's occurrences with an older generation. Must run inside the
     * commit transaction.
     *
     * @param list<Occurrence> $occurrences
     */
    public function replaceSnapshot(int $sourceId, array $occurrences, int $generation): SnapshotDiff;

    public function deleteSource(int $sourceId): void;
}
