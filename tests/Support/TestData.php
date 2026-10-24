<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Support;

use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Event\ProviderEvent;
use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Domain\Source\SourceStatus;
use SW\Calendar\Domain\Source\Visibility;

final class TestData
{
    /**
     * @param list<string> $roles
     */
    public static function source(
        int $id = 1,
        Visibility $visibility = Visibility::Public,
        array $roles = [],
        SourceStatus $status = SourceStatus::Active,
        ?SourceSettings $settings = null,
        int $connectionId = 1,
        string $tz = 'Europe/Berlin',
    ): Source {
        return new Source($id, $connectionId, 'ext-' . $id, 'Source ' . $id, '#336699', $tz, $visibility, $roles, $settings ?? new SourceSettings(), 1, $status);
    }

    public static function event(
        string $id,
        string $start,
        string $end,
        string $tz = 'Europe/Berlin',
        ?string $rrule = null,
        ?string $seriesId = null,
        ?string $recurrenceId = null,
        OccurrenceStatus $status = OccurrenceStatus::Confirmed,
        bool $allDay = false,
        string $title = 'Event',
        array $exdates = [],
        array $rdates = [],
    ): ProviderEvent {
        $zone = new \DateTimeZone($allDay ? 'UTC' : $tz);
        return new ProviderEvent(
            $id,
            $seriesId,
            $recurrenceId,
            $title,
            '',
            '',
            '',
            '',
            new \DateTimeImmutable($start, $zone),
            new \DateTimeImmutable($end, $zone),
            $allDay,
            $allDay ? 'UTC' : $tz,
            $status,
            $rrule,
            array_map(static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d, $zone), $exdates),
            array_map(static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d, $zone), $rdates),
        );
    }
}
