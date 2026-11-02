<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Local;

use SW\Calendar\Application\Render\QueryRange;

final class LocalEventQuery
{
    /**
     * @param list<string> $calendarIds Already authorized calendars only.
     * @param list<string> $excludeIds Adapter-local event identities.
     */
    public function __construct(
        public readonly array $calendarIds,
        public readonly QueryRange $range,
        public readonly ?string $search = null,
        public readonly ?string $location = null,
        public readonly array $excludeIds = [],
        public readonly bool $descending = false,
        public readonly int $limit = 100,
        public readonly int $offset = 0,
        public readonly int $sortOffsetSeconds = 0,
        public readonly bool $endedBeforeRangeEnd = false,
    ) {
    }
}
