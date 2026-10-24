<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

/**
 * Resolved display window. Timed occurrences are compared with the UTC bounds, all-day occurrences (floating dates)
 * with the local wall-clock bounds expressed as UTC values (docs/decisions.md D-08). Both are half open.
 */
final class QueryRange
{
    public function __construct(
        public readonly \DateTimeImmutable $startUtc,
        public readonly \DateTimeImmutable $endUtc,
        public readonly \DateTimeImmutable $floatingStart,
        public readonly \DateTimeImmutable $floatingEnd,
        /** Local first day of the displayed month (month mode) or null. */
        public readonly ?\DateTimeImmutable $month = null,
        /** True if the window starts at "now" (list of upcoming events): results expire when events end. */
        public readonly bool $relativeToNow = false,
    ) {
    }

    public static function fromLocal(\DateTimeImmutable $localStart, \DateTimeImmutable $localEnd, ?\DateTimeImmutable $month = null, bool $relative = false): self
    {
        $utc = new \DateTimeZone('UTC');
        return new self(
            $localStart->setTimezone($utc),
            $localEnd->setTimezone($utc),
            new \DateTimeImmutable($localStart->format('Y-m-d H:i:s'), $utc),
            new \DateTimeImmutable($localEnd->format('Y-m-d H:i:s'), $utc),
            $month,
            $relative,
        );
    }
}
