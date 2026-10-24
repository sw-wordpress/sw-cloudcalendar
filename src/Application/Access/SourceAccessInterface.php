<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Access;

interface SourceAccessInterface
{
    public function canReadSource(int $sourceId, ?int $wpUserId): bool;

    /**
     * @param int[] $sourceIds
     * @return int[] Allowed ids in input order, without duplicates.
     */
    public function filterAllowedSources(array $sourceIds, ?int $wpUserId): array;
}
