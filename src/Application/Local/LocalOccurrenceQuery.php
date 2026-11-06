<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Local;

use SW\Calendar\Application\Port\ConnectionRepositoryInterface;
use SW\Calendar\Application\Port\OccurrenceQueryInterface;
use SW\Calendar\Application\Port\SourceRepositoryInterface;
use SW\Calendar\Application\Render\QueryRange;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Source\Source;

/** Federates bounded, sorted pages without persisting external events or identity mappings. */
final class LocalOccurrenceQuery implements OccurrenceQueryInterface
{
    /** @var array<int, bool> Immutable source/connection kind only; never cache ACLs or availability. */
    private array $localKinds = [];

    public function __construct(
        private readonly OccurrenceQueryInterface $stored,
        private readonly SourceRepositoryInterface $sources,
        private readonly ConnectionRepositoryInterface $connections,
        private readonly LocalSourceRegistry $registry,
        private readonly \SW\Calendar\Support\LoggerInterface $logger = new \SW\Calendar\Support\NullLogger(),
    ) {
    }

    public function isLocal(int $sourceId): bool
    {
        if (array_key_exists($sourceId, $this->localKinds)) {
            return $this->localKinds[$sourceId];
        }
        $source = $this->sources->find($sourceId);
        $connection = $source === null ? null : $this->connections->find($source->connectionId);
        if ($connection === null) {
            return false;
        }
        return $this->localKinds[$sourceId] = $connection->provider === Provider::Local;
    }

    public function canRead(Source $source, ?int $userId): bool
    {
        $connection = $this->connections->find($source->connectionId);
        if ($connection?->provider !== Provider::Local) {
            return true;
        }
        $adapter = $this->registry->get((string) $connection->setting('adapter', ''));
        try {
            return $connection->status === ConnectionStatus::Connected && $adapter !== null && $adapter->canReadCalendar($source->externalId, $userId);
        } catch (\Throwable) {
            $this->logger->log('error', 'local_source_unavailable', 'Local source access check failed.', ['source_id' => $source->id]);
            return false;
        }
    }

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
        bool $endedBeforeRangeEnd = false,
    ): array {
        $stored = [];
        $groups = [];
        foreach ($this->sources->findMany($sourceIds) as $source) {
            $connection = $this->connections->find($source->connectionId);
            if ($connection?->provider !== Provider::Local) {
                $stored[] = $source->id;
            } elseif ($connection->status === ConnectionStatus::Connected) {
                $groups[$connection->id]['adapter'] = (string) $connection->setting('adapter', '');
                $groups[$connection->id]['sources'][$source->externalId] = $source;
            }
        }
        if ($groups === []) {
            return $this->stored->find($stored, $range, $search, $location, $excludeIds, $descending, $limit, $offset, $sortOffsetSeconds, $endedBeforeRangeEnd);
        }
        // For a single stream push the offset into SQL. For mixed streams each contributes its first offset+limit
        // candidates; applying offset separately to each stream would drop valid events from the combined page.
        $single = $stored === [] && count($groups) === 1;
        if (!$single && $offset + $limit > 10000) {
            throw new \SW\Calendar\Domain\CalendarException(\SW\Calendar\Domain\ErrorCode::LimitExceeded, 'Mixed-source pagination is limited to 10000 candidates per source. Narrow the date range.');
        }
        $take = $single ? $limit : $offset + $limit;
        $skip = $single ? $offset : 0;
        $found = $stored === [] ? ['items' => [], 'total' => 0] : $this->stored->find($stored, $range, $search, $location, array_values(array_filter($excludeIds, 'is_int')), $descending, $take, 0, $sortOffsetSeconds, $endedBeforeRangeEnd);
        foreach ($groups as $group) {
            $adapter = $this->registry->get($group['adapter']);
            if ($adapter === null) {
                continue;
            }
            $excluded = [];
            foreach ($excludeIds as $id) {
                $ref = is_string($id) ? EventReference::parse($id) : null;
                if ($ref !== null && in_array($ref['source'], array_map(static fn (Source $s): int => $s->id, $group['sources']), true)) {
                    $excluded[] = $ref['id'];
                }
            }
            uasort($group['sources'], static fn (Source $a, Source $b): int => $a->id <=> $b->id);
            try {
                $page = $adapter->query(new LocalEventQuery(array_map('strval', array_keys($group['sources'])), $range, $search, $location, $excluded, $descending, $take, $skip, $sortOffsetSeconds, $endedBeforeRangeEnd));
            } catch (\Throwable) {
                $this->logger->log('error', 'local_source_unavailable', 'Local source query failed.', ['adapter' => $group['adapter']]);
                continue;
            }
            foreach ($page->items as $event) {
                $source = $group['sources'][$event->calendarId] ?? null;
                if ($source !== null && $event->status !== OccurrenceStatus::Cancelled) {
                    $found['items'][] = $event->occurrence($source->id);
                }
            }
            $found['total'] += $page->total;
        }
        usort($found['items'], static function (Occurrence $a, Occurrence $b) use ($descending, $sortOffsetSeconds): int {
            $timeA = $a->startUtc->getTimestamp() - ($a->allDay ? $sortOffsetSeconds : 0);
            $timeB = $b->startUtc->getTimestamp() - ($b->allDay ? $sortOffsetSeconds : 0);
            return ($descending ? $timeB <=> $timeA : $timeA <=> $timeB) ?: ($a->sourceId <=> $b->sourceId) ?: (is_int($a->id) && is_int($b->id) ? $a->id <=> $b->id : strcmp($a->providerEventId, $b->providerEventId));
        });
        return ['items' => array_slice($found['items'], $single ? 0 : $offset, $limit), 'total' => $found['total']];
    }

    public function findById(int|string $id): ?Occurrence
    {
        if (is_int($id) || ctype_digit($id)) {
            return $this->stored->findById((int) $id);
        }
        $ref = EventReference::parse($id);
        $source = $ref === null ? null : $this->sources->find($ref['source']);
        $connection = $source === null ? null : $this->connections->find($source->connectionId);
        if ($source === null || $connection?->provider !== Provider::Local || $connection->status !== ConnectionStatus::Connected) {
            return null;
        }
        try {
            $event = $this->registry->get((string) $connection->setting('adapter', ''))?->event($source->externalId, $ref['id']);
        } catch (\Throwable) {
            $this->logger->log('error', 'local_source_unavailable', 'Local source detail lookup failed.', ['source_id' => $source->id]);
            return null;
        }
        return $event !== null && $event->calendarId === $source->externalId && $event->id === $ref['id'] ? $event->occurrence($source->id) : null;
    }
}
