<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Domain\View\DisplayMode;

/**
 * Turns a filter into the query window (SPECIFICATIONS.md §10.2):
 * - month mode: complete weeks covering the selected month (`month`, else month of `start`, else current month);
 * - `start`/`end`: local dates, `[start 00:00, end + 1 day 00:00)`;
 * - `days`: from now until the end of the N-th local day (today counts as day 1);
 * - default: from now for 365 days.
 * `past=true` moves an open start back by 365 days. Ongoing events always match (overlap semantics).
 */
final class RangeResolver
{
    public const DEFAULT_FUTURE_DAYS = 365;
    public const PAST_DAYS = 365;

    /**
     * @param int $weekStart 0 = Sunday … 6 = Saturday (WordPress `start_of_week`).
     */
    public function resolve(RenderFilter $filter, DisplayMode $mode, \DateTimeZone $tz, \DateTimeImmutable $now, int $weekStart = 1): QueryRange
    {
        $localNow = $now->setTimezone($tz);
        $today = $localNow->setTime(0, 0);

        if ($mode === DisplayMode::Month) {
            $anchor = $filter->month !== null
                ? new \DateTimeImmutable($filter->month . '-01 00:00:00', $tz)
                : ($filter->startDate !== null ? new \DateTimeImmutable(substr($filter->startDate, 0, 7) . '-01 00:00:00', $tz) : $today->modify('first day of this month'));
            $first = $anchor->setTime(0, 0);
            $last = $first->modify('last day of this month');
            $lead = ((int) $first->format('w') - $weekStart + 7) % 7;
            $trail = ($weekStart + 6 - (int) $last->format('w') + 7) % 7;
            return QueryRange::fromLocal(
                $first->modify('-' . $lead . ' days'),
                $last->modify('+' . ($trail + 1) . ' days'),
                $first,
            );
        }

        if ($filter->startDate !== null || $filter->endDate !== null) {
            $start = $filter->startDate !== null ? new \DateTimeImmutable($filter->startDate . ' 00:00:00', $tz) : ($filter->past === true ? $today->modify('-' . self::PAST_DAYS . ' days') : $localNow);
            $end = $filter->endDate !== null
                ? (new \DateTimeImmutable($filter->endDate . ' 00:00:00', $tz))->modify('+1 day')
                : $start->setTime(0, 0)->modify('+' . self::DEFAULT_FUTURE_DAYS . ' days');
            return QueryRange::fromLocal($start, $end, null, $filter->startDate === null && $filter->past !== true);
        }

        $days = $filter->days ?? self::DEFAULT_FUTURE_DAYS;
        $end = $today->modify('+' . $days . ' days');
        if ($filter->past === true) {
            return QueryRange::fromLocal($today->modify('-' . self::PAST_DAYS . ' days'), $end);
        }
        return QueryRange::fromLocal($localNow, $end, null, true);
    }
}
