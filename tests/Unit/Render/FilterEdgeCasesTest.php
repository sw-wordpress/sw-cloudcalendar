<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Render\FilterSchema;
use SW\Calendar\Application\Render\RangeResolver;
use SW\Calendar\Application\Render\RenderFilter;
use SW\Calendar\Domain\View\DisplayMode;

final class FilterEdgeCasesTest extends TestCase
{
    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function booleanValues(): array
    {
        return [
            'yes' => ['yes', true],
            'on' => ['ON', true],
            'one' => [1, true],
            'boolean true' => [true, true],
            'no' => ['NO', false],
            'off' => ['off', false],
            'zero' => [0, false],
            'boolean false' => [false, false],
        ];
    }

    #[DataProvider('booleanValues')]
    public function testBooleanNormalization(mixed $raw, bool $expected): void
    {
        $result = (new FilterSchema())->normalize(['past' => $raw, 'navigation' => $raw]);
        self::assertTrue($result->isValid());
        self::assertSame($expected, $result->filter->past);
        self::assertSame($expected, $result->filter->navigation);
    }

    public function testInvalidBooleanIsRejectedWithoutChangingDefault(): void
    {
        $result = (new FilterSchema())->normalize(['show_images' => 'sometimes']);
        self::assertSame(['show_images' => 'invalid_boolean'], $result->errors);
        self::assertNull($result->filter->showImages);
    }

    public function testIdsAreDeduplicatedAndBounded(): void
    {
        $schema = new FilterSchema();
        $result = $schema->normalize(['source' => [4, ' 2 ', 4], 'exclude' => '3, 3,1']);
        self::assertTrue($result->isValid());
        self::assertSame([4, 2], $result->filter->sourceIds);
        self::assertSame([3, 1], $result->filter->exclude);

        $tooMany = $schema->normalize(['source' => range(1, FilterSchema::MAX_IDS + 1)]);
        self::assertSame('invalid_ids', $tooMany->errors['source']);
        self::assertNull($tooMany->filter->sourceIds);
    }

    public function testInvalidPaginationAndRangeAreRejected(): void
    {
        $schema = new FilterSchema();
        $result = $schema->normalize([
            'page' => 0,
            'per_page' => FilterSchema::MAX_LIMIT + 1,
            'start' => '2026-11-30',
            'end' => '2026-11-01',
        ]);
        self::assertSame('out_of_range', $result->errors['page']);
        self::assertSame('out_of_range', $result->errors['per_page']);
        self::assertSame('end_before_start', $result->errors['end']);
        self::assertSame('2026-11-30', $result->filter->startDate);
        self::assertNull($result->filter->endDate);

        $long = $schema->normalize(['start' => '2020-01-01', 'end' => '2026-01-01']);
        self::assertSame('range_too_long', $long->errors['end']);
        self::assertNull($long->filter->endDate);
    }

    public function testPaginationAcceptsNegativePagesAndMaximumLimits(): void
    {
        $result = (new FilterSchema())->normalize([
            'page' => -2,
            'per_page' => FilterSchema::MAX_LIMIT,
            'limit' => FilterSchema::MAX_LIMIT,
            'days' => FilterSchema::MAX_DAYS,
        ]);
        self::assertTrue($result->isValid());
        self::assertSame(-2, $result->filter->page);
        self::assertSame(FilterSchema::MAX_LIMIT, $result->filter->perPage);
        self::assertSame(FilterSchema::MAX_LIMIT, $result->filter->limit);
        self::assertSame(FilterSchema::MAX_DAYS, $result->filter->days);
    }

    public function testCacheHashIgnoresSourceAndExcludeOrder(): void
    {
        $first = new RenderFilter(sourceIds: [3, 1, 2], exclude: [9, 4], search: 'test');
        $second = new RenderFilter(sourceIds: [2, 3, 1], exclude: [4, 9], search: 'test');
        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame($first->hash(), $second->hash());
        self::assertSame([3, 1, 2], $first->sourceIds, 'Canonicalization must not mutate the input');
        self::assertNotSame($first->hash(), (new RenderFilter(sourceIds: [1, 2, 3], exclude: [4, 9], search: 'other'))->hash());
    }

    public function testDefaultsNeverExpandExplicitSourceRestrictions(): void
    {
        $defaults = new RenderFilter(sourceIds: [1, 2], exclude: [4, 5], showImages: true);
        $merged = (new RenderFilter(sourceIds: [9], exclude: [5, 6], showImages: false))->withDefaults($defaults);
        self::assertSame([], $merged->sourceIds);
        self::assertSame([4, 5, 6], $merged->exclude);
        self::assertFalse($merged->showImages);

        self::assertSame([1, 2], (new RenderFilter())->withDefaults($defaults)->sourceIds);
    }

    public function testMonthGridSupportsSundayWeekStartAcrossYearBoundary(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $range = (new RangeResolver())->resolve(
            new RenderFilter(month: '2027-01'),
            DisplayMode::Month,
            $tz,
            new \DateTimeImmutable('2026-12-15T12:00:00Z'),
            0
        );
        self::assertSame('2026-12-27', $range->floatingStart->format('Y-m-d'));
        self::assertSame('2027-02-07', $range->floatingEnd->format('Y-m-d'));
        self::assertSame('2027-01-01', $range->month?->format('Y-m-d'));
        self::assertFalse($range->relativeToNow);
    }

    public function testPastRangeStartsAtLocalMidnight(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('2026-10-31T12:00:00Z');
        $range = (new RangeResolver())->resolve(new RenderFilter(days: 2, past: true), DisplayMode::List, $tz, $now);
        self::assertSame('2025-10-31 00:00', $range->floatingStart->format('Y-m-d H:i'));
        self::assertSame('2026-11-02 00:00', $range->floatingEnd->format('Y-m-d H:i'));
        self::assertFalse($range->relativeToNow);
    }
}
