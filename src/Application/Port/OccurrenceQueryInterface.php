<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Application\Render\QueryRange;
use SW\Calendar\Domain\Event\Occurrence;

interface OccurrenceQueryInterface
{
    /**
     * Overlap query (SPECIFICATIONS.md §4.2) over the given, already AUTHORIZED source ids; visible statuses only;
     * deterministic order by start, source id, id.
     *
     * @param list<int> $sourceIds
     * @param list<int> $excludeIds
     * @return array{items: list<Occurrence>, total: int}
     */
    public function find(
        array $sourceIds,
        QueryRange $range,
        ?string $search,
        ?string $location,
        array $excludeIds,
        bool $descending,
        int $limit,
        int $offset,
        int $sortOffsetSeconds,
    ): array;

    public function findById(int $id): ?Occurrence;
}
