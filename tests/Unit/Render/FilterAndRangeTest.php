<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Render\FilterSchema;
use SW\Calendar\Application\Render\RangeResolver;
use SW\Calendar\Application\Render\RenderFilter;
use SW\Calendar\Domain\View\DisplayMode;

final class FilterAndRangeTest extends TestCase
{
    public function testNormalizesAndValidatesShortcodeAttributes(): void
    {
        $result = (new FilterSchema())->normalize([
            'id' => '12', 'source' => '2, 3,3', 'view' => 'LIST', 'limit' => '5', 'days' => '30',
            'search' => 'Gottesdienst', 'show_images' => 'true', 'show_attachments' => 'no', 'unknown' => 'x',
        ]);
        self::assertTrue($result->isValid());
        self::assertSame([2, 3], $result->filter->sourceIds);
        self::assertSame(DisplayMode::List, $result->filter->mode);
        self::assertSame(5, $result->filter->limit);
        self::assertTrue($result->filter->showImages);
        self::assertFalse($result->filter->showAttachments);
        self::assertSame(['unknown'], $result->unknown);
    }

    public function testRejectsOutOfRangeAndInjection(): void
    {
        $result = (new FilterSchema())->normalize([
            'limit' => '101', 'days' => '0', 'start' => '2026-02-30', 'source' => '1 OR 1=1', 'sort' => 'name',
            'search' => str_repeat('x', 151), 'category' => 'jugend', 'end' => '2026-01-01; DROP',
        ]);
        self::assertSame(['limit', 'days', 'start', 'source', 'sort', 'search', 'category', 'end'], array_keys($result->errors));
        self::assertNull($result->filter->limit);
        self::assertNull($result->filter->sourceIds);
    }

    public function testSourceFilterCanOnlyNarrowDefaults(): void
    {
        $request = new RenderFilter(sourceIds: [2, 9]);
        $merged = $request->withDefaults(new RenderFilter(sourceIds: [1, 2, 3]));
        self::assertSame([2], $merged->sourceIds);
    }

    public function testPrecedenceStartEndOverDaysOverDefault(): void
    {
        $merged = (new RenderFilter(startDate: '2026-11-01', endDate: '2026-11-30'))->withDefaults(new RenderFilter(days: 7));
        self::assertNull($merged->days);
        $merged = (new RenderFilter(days: 3))->withDefaults(new RenderFilter(startDate: '2026-01-01'));
        self::assertNull($merged->startDate);
        self::assertSame(3, $merged->days);
    }

    public function testRanges(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('2026-10-24T12:00:00Z');
        $resolver = new RangeResolver();

        $range = $resolver->resolve(new RenderFilter(startDate: '2026-11-01', endDate: '2026-11-30'), DisplayMode::List, $tz, $now);
        self::assertSame('2026-10-31T23:00:00Z', $range->startUtc->format('Y-m-d\TH:i:s\Z'));
        self::assertSame('2026-11-30T23:00:00Z', $range->endUtc->format('Y-m-d\TH:i:s\Z'), 'End date is inclusive: [start, end + 1 day)');
        self::assertSame('2026-12-01 00:00', $range->floatingEnd->format('Y-m-d H:i'));

        $days = $resolver->resolve(new RenderFilter(days: 2), DisplayMode::List, $tz, $now);
        self::assertTrue($days->relativeToNow);
        self::assertEquals($now, $days->startUtc);
        self::assertSame('2026-10-26 00:00', $days->floatingEnd->format('Y-m-d H:i'), 'Today counts as day 1');

        $month = $resolver->resolve(new RenderFilter(month: '2026-11'), DisplayMode::Month, $tz, $now, 1);
        self::assertSame('2026-10-26', $month->floatingStart->format('Y-m-d'), 'Grid starts on Monday');
        self::assertSame('2026-12-07', $month->floatingEnd->format('Y-m-d'), 'Grid ends after the last Sunday');
        self::assertSame('2026-11-01', $month->month?->format('Y-m-d'));
    }
}
