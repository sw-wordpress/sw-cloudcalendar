<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Sync\OccurrenceBuilder;
use SW\Calendar\Application\Sync\RecurrenceExpander;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Tests\Support\PlainSanitizer;
use SW\Calendar\Tests\Support\TestData;

final class RecurrenceTest extends TestCase
{
    private function window(string $start = '2026-01-01', string $end = '2027-01-01'): SyncWindow
    {
        return new SyncWindow(new \DateTimeImmutable($start . 'T00:00:00Z'), new \DateTimeImmutable($end . 'T00:00:00Z'));
    }

    /**
     * @return list<Occurrence>
     */
    private function build(array $items, ?SyncWindow $window = null, ?SourceSettings $settings = null): array
    {
        $builder = new OccurrenceBuilder(new RecurrenceExpander(), new PlainSanitizer());
        return $builder->build(1, $items, $window ?? $this->window(), $settings ?? new SourceSettings());
    }

    public function testWeeklySeriesKeepsLocalTimeAcrossDstChange(): void
    {
        // Sunday 10:00 Europe/Berlin across the change on 2026-10-25 (CEST -> CET).
        $master = TestData::event('s1', '2026-10-18 10:00', '2026-10-18 11:00', rrule: 'FREQ=WEEKLY;COUNT=3');
        $occurrences = $this->build([$master]);
        self::assertCount(3, $occurrences);
        self::assertSame('2026-10-18 08:00', $occurrences[0]->startUtc->format('Y-m-d H:i'));
        self::assertSame('2026-10-25 09:00', $occurrences[1]->startUtc->format('Y-m-d H:i'), 'After the DST change 10:00 local is 09:00 UTC');
        self::assertSame('2026-11-01 09:00', $occurrences[2]->startUtc->format('Y-m-d H:i'));
        self::assertSame('2026-10-25 10:00', $occurrences[1]->endUtc->format('Y-m-d H:i'));
    }

    public function testExdateRemovesInstanceAndOverrideMovesIt(): void
    {
        $master = TestData::event('s1', '2026-03-02 09:00', '2026-03-02 10:00', rrule: 'FREQ=DAILY;COUNT=5', exdates: ['2026-03-03 09:00']);
        // 2026-03-04 09:00 Berlin = 08:00Z is moved to 14:00.
        $moved = TestData::event('s1#2026-03-04T08:00:00Z', '2026-03-04 14:00', '2026-03-04 15:00', seriesId: 's1', recurrenceId: '2026-03-04T08:00:00Z', title: 'Moved');
        $cancelled = TestData::event('s1#2026-03-05T08:00:00Z', '2026-03-05 09:00', '2026-03-05 10:00', seriesId: 's1', recurrenceId: '2026-03-05T08:00:00Z', status: OccurrenceStatus::Cancelled);
        $occurrences = $this->build([$master, $moved, $cancelled]);
        $starts = array_map(static fn (Occurrence $o): string => $o->startUtc->format('m-d H:i'), $occurrences);
        self::assertSame(['03-02 08:00', '03-04 13:00', '03-06 08:00'], $starts);
        self::assertSame('Moved', $occurrences[1]->title);
        // Moving keeps the identity of the instance.
        self::assertSame(Occurrence::keyFor('s1', '2026-03-04T08:00:00Z'), $occurrences[1]->occurrenceKey);
    }

    public function testRruleAndRdateAreCombined(): void
    {
        $master = TestData::event('s1', '2026-05-04 18:00', '2026-05-04 19:00', rrule: 'FREQ=WEEKLY;COUNT=2', rdates: ['2026-05-20 18:00']);
        $occurrences = $this->build([$master]);
        self::assertSame(['05-04', '05-11', '05-20'], array_map(static fn (Occurrence $o): string => $o->startUtc->format('m-d'), $occurrences));
    }

    public function testAllDayEventsAreFloatingWithExclusiveEnd(): void
    {
        $event = TestData::event('a1', '2026-12-24', '2026-12-27', allDay: true);
        $occurrences = $this->build([$event]);
        self::assertTrue($occurrences[0]->allDay);
        self::assertSame('2026-12-24 00:00', $occurrences[0]->startUtc->format('Y-m-d H:i'));
        self::assertSame('2026-12-27 00:00', $occurrences[0]->endUtc->format('Y-m-d H:i'));
    }

    public function testAllDayRecurringYearly(): void
    {
        $event = TestData::event('b1', '2020-02-29', '2020-03-01', rrule: 'FREQ=YEARLY', allDay: true);
        $occurrences = $this->build([$event], $this->window('2023-01-01', '2029-01-01'));
        self::assertSame(['2024-02-29', '2028-02-29'], array_map(static fn (Occurrence $o): string => $o->startUtc->format('Y-m-d'), $occurrences));
    }

    public function testInfiniteRuleIsBoundedByWindow(): void
    {
        $event = TestData::event('d1', '2000-01-01 07:00', '2000-01-01 07:30', rrule: 'FREQ=DAILY');
        $occurrences = $this->build([$event], $this->window('2026-01-01', '2026-02-01'));
        self::assertCount(31, $occurrences);
    }

    public function testTooManyInstancesIsReportedNotSilentlyTruncated(): void
    {
        $event = TestData::event('h1', '2026-01-01 00:00', '2026-01-01 00:10', rrule: 'FREQ=HOURLY');
        $builder = new OccurrenceBuilder(new RecurrenceExpander(100), new PlainSanitizer());
        $occurrences = $builder->build(1, [$event], $this->window('2026-01-01', '2026-02-01'), new SourceSettings());
        self::assertSame([], $occurrences);
        self::assertNotEmpty($builder->warnings);
    }

    public function testOccurrenceLimitPerSourceThrows(): void
    {
        $this->expectException(CalendarException::class);
        $event = TestData::event('d1', '2026-01-01 07:00', '2026-01-01 07:30', rrule: 'FREQ=DAILY');
        (new OccurrenceBuilder(new RecurrenceExpander(), new PlainSanitizer(), 10))->build(1, [$event], $this->window(), new SourceSettings());
    }

    public function testOngoingEventOverlappingWindowStartIsKept(): void
    {
        $event = TestData::event('o1', '2025-12-31 22:00', '2026-01-01 03:00', tz: 'UTC');
        self::assertCount(1, $this->build([$event]));
        $before = TestData::event('o2', '2025-12-31 22:00', '2026-01-01 00:00', tz: 'UTC');
        self::assertCount(0, $this->build([$before]), 'Exclusive end: an event ending at the window start is outside');
    }

    public function testExclusionsAndCancelledMaster(): void
    {
        $private = TestData::event('p1', '2026-02-01 10:00', '2026-02-01 11:00');
        $private = new \SW\Calendar\Domain\Event\ProviderEvent(
            $private->itemId,
            null,
            null,
            'Private',
            '',
            '',
            '',
            '',
            $private->start,
            $private->end,
            false,
            'Europe/Berlin',
            OccurrenceStatus::Confirmed,
            null,
            [],
            [],
            'default',
            true,
        );
        $focus = new \SW\Calendar\Domain\Event\ProviderEvent(
            'f1',
            null,
            null,
            'Focus',
            '',
            '',
            '',
            '',
            $private->start,
            $private->end,
            false,
            'Europe/Berlin',
            OccurrenceStatus::Confirmed,
            null,
            [],
            [],
            'focusTime',
        );
        $cancelled = TestData::event('c1', '2026-02-01 10:00', '2026-02-01 11:00', rrule: 'FREQ=DAILY;COUNT=3', status: OccurrenceStatus::Cancelled);
        self::assertCount(1, $this->build([$private, $focus, $cancelled]), 'private included by default, focusTime excluded, cancelled series hidden');
        self::assertCount(0, $this->build([$private], null, SourceSettings::fromArray(['exclude_private_events' => true])));
    }

    public function testKeysAreDeterministic(): void
    {
        $master = TestData::event('s1', '2026-03-02 09:00', '2026-03-02 10:00', rrule: 'FREQ=DAILY;COUNT=3');
        $a = $this->build([$master]);
        $b = $this->build([$master]);
        self::assertSame(array_map(static fn ($o) => $o->occurrenceKey . $o->payloadHash(), $a), array_map(static fn ($o) => $o->occurrenceKey . $o->payloadHash(), $b));
    }
}
