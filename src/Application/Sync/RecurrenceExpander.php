<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Sync;

use Sabre\VObject\Recur\RRuleIterator;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Event\ProviderEvent;
use SW\Calendar\Domain\Provider\SyncWindow;

/**
 * Bounded local expansion of recurring series (SPECIFICATIONS.md §3.3, §6.1).
 *
 * The RRULE engine is sabre/vobject's RFC 5545 RRuleIterator (no own RRULE logic). This class only combines its
 * output with DTSTART, RDATE, EXDATE and overridden instances: sabre's EventIterator ignores RRULE when RDATE is
 * present, which would drop instances (docs/decisions.md D-14).
 *
 * Wall-clock times are iterated in the series' own time zone, so DST changes keep local times stable.
 */
final class RecurrenceExpander
{
    /** Hard limit of iterations while skipping to the window (protects against FREQ=SECONDLY style rules). */
    public const MAX_ITERATIONS = 200000;

    public function __construct(public readonly int $maxInstancesPerSeries = 3660)
    {
    }

    /**
     * @param list<ProviderEvent> $overrides Overridden instances of the series (recurrenceId set).
     * @return list<array{recurrenceId: string, event: ProviderEvent, start: \DateTimeImmutable, end: \DateTimeImmutable}>
     * @throws CalendarException LIMIT_EXCEEDED when the series produces more instances than allowed in the window.
     */
    public function expand(ProviderEvent $master, array $overrides, SyncWindow $window): array
    {
        $duration = $master->end->getTimestamp() - $master->start->getTimestamp();
        $durationDays = $master->allDay ? max(1, (int) round($duration / 86400)) : 0;
        $windowStart = $window->start;
        $windowEnd = $window->end;
        if ($master->allDay) {
            // All-day dates are floating; compare with a window widened by the maximal UTC offset.
            $windowStart = $windowStart->modify('-14 hours');
            $windowEnd = $windowEnd->modify('+14 hours');
        }
        $earliestStart = $windowStart->modify('-' . max(0, $duration) . ' seconds');

        /** @var array<string, \DateTimeImmutable> $starts */
        $starts = [];
        $starts[$this->instanceKey($master->start, $master->allDay)] = $master->start;
        if ($master->rrule !== null && trim($master->rrule) !== '') {
            try {
                $iterator = new RRuleIterator($master->rrule, \DateTime::createFromImmutable($master->start));
            } catch (\Throwable $e) {
                throw new CalendarException(ErrorCode::FetchFailed, 'Invalid recurrence rule.', null, ['series' => substr(hash('sha256', $master->seriesKey()), 0, 12)], $e);
            }
            $iterations = 0;
            while ($iterator->valid()) {
                if (++$iterations > self::MAX_ITERATIONS) {
                    throw new CalendarException(ErrorCode::LimitExceeded, 'Recurrence rule exceeds the iteration limit.');
                }
                $current = \DateTimeImmutable::createFromInterface($iterator->current());
                if ($current >= $windowEnd) {
                    break;
                }
                if ($current >= $earliestStart) {
                    $starts[$this->instanceKey($current, $master->allDay)] = $current;
                    if (count($starts) > $this->maxInstancesPerSeries) {
                        throw new CalendarException(ErrorCode::LimitExceeded, 'Recurring series has too many instances in the sync window.', null, ['limit' => $this->maxInstancesPerSeries]);
                    }
                }
                $iterator->next();
            }
        }
        foreach ($master->rdates as $rdate) {
            $rdate = $master->allDay ? $rdate : $rdate->setTimezone($master->start->getTimezone());
            $starts[$this->instanceKey($rdate, $master->allDay)] = $rdate;
        }
        foreach ($master->exdates as $exdate) {
            unset($starts[$this->instanceKey($exdate, $master->allDay)]);
        }

        $byRecurrence = [];
        foreach ($overrides as $override) {
            if ($override->recurrenceId !== null) {
                $byRecurrence[$this->normalizeRecurrenceId($override->recurrenceId, $master->allDay)] = $override;
            }
        }

        $instances = [];
        foreach ($starts as $key => $start) {
            $override = $byRecurrence[$key] ?? null;
            unset($byRecurrence[$key]);
            if ($override !== null) {
                if ($override->status === OccurrenceStatus::Cancelled) {
                    continue;
                }
                $instances[] = ['recurrenceId' => $key, 'event' => $override, 'start' => $override->start, 'end' => $override->end];
                continue;
            }
            $end = $master->allDay ? $start->modify('+' . $durationDays . ' days') : $start->modify('+' . $duration . ' seconds');
            $instances[] = ['recurrenceId' => $key, 'event' => $master, 'start' => $start, 'end' => $end];
        }
        // Overrides without a generated slot (moved in from outside the window, EXDATE-ed slots re-added, RANGE use).
        foreach ($byRecurrence as $key => $override) {
            if ($override->status !== OccurrenceStatus::Cancelled && !isset($starts[$key]) && !$this->isExcluded($key, $master)) {
                $instances[] = ['recurrenceId' => $key, 'event' => $override, 'start' => $override->start, 'end' => $override->end];
            }
        }

        $result = array_values(array_filter(
            $instances,
            static fn (array $i): bool => $i['start'] < $windowEnd && max($i['end'], $i['start']->modify('+1 second')) > $windowStart
        ));
        usort($result, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        return $result;
    }

    /**
     * Canonical instance identity: UTC timestamp for timed series, date for all-day series.
     */
    public function instanceKey(\DateTimeImmutable $start, bool $allDay): string
    {
        return $allDay ? $start->format('Y-m-d') : $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public function normalizeRecurrenceId(string $recurrenceId, bool $allDay): string
    {
        try {
            $date = new \DateTimeImmutable($recurrenceId, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $recurrenceId;
        }
        return $this->instanceKey($date, $allDay);
    }

    private function isExcluded(string $key, ProviderEvent $master): bool
    {
        foreach ($master->exdates as $exdate) {
            if ($this->instanceKey($exdate, $master->allDay) === $key) {
                return true;
            }
        }
        return false;
    }
}
