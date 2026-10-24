<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

/**
 * Half open UTC window [start, end) of occurrences kept locally.
 */
final class SyncWindow
{
    public function __construct(
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
    ) {
        if ($end <= $start) {
            throw new \InvalidArgumentException('Sync window end must be after its start.');
        }
    }

    /**
     * Window relative to "now", aligned to whole UTC days so that it changes at most once a day. Providers that bind
     * cursors to the window (Microsoft delta) therefore restart their delta track at most daily.
     */
    public static function around(\DateTimeImmutable $now, int $pastDays, int $futureDays): self
    {
        $day = $now->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        return new self($day->modify('-' . $pastDays . ' days'), $day->modify('+' . ($futureDays + 1) . ' days'));
    }

    public function equals(self $other): bool
    {
        return $this->start == $other->start && $this->end == $other->end;
    }
}
