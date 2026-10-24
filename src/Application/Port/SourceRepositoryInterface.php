<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Domain\Source\SourceStatus;

interface SourceRepositoryInterface
{
    public function find(int $id): ?Source;

    /**
     * @param list<int> $ids
     * @return array<int, Source> Indexed by id; unknown ids are omitted.
     */
    public function findMany(array $ids): array;

    /**
     * @return list<Source>
     */
    public function all(): array;

    /** Increments the data revision (invalidates render caches keyed by it). */
    public function bumpRevision(int $id): void;

    public function markSuccess(int $id, \DateTimeImmutable $at): void;

    public function setStatus(int $id, SourceStatus $status): void;
}
