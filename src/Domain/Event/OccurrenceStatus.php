<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Event;

enum OccurrenceStatus: string
{
    case Confirmed = 'confirmed';
    case Tentative = 'tentative';
    case Cancelled = 'cancelled';

    public static function fromProvider(?string $value): self
    {
        return match (strtolower(trim((string) $value))) {
            'tentative' => self::Tentative,
            'cancelled', 'canceled' => self::Cancelled,
            default => self::Confirmed,
        };
    }

    /**
     * Statuses shown to visitors. Deviation from the SQL sketch in SPECIFICATIONS.md §4.2 (`status = 'confirmed'`):
     * tentative appointments are real appointments and must not disappear silently (docs/decisions.md D-02).
     *
     * @return list<string>
     */
    public static function visibleValues(): array
    {
        return [self::Confirmed->value, self::Tentative->value];
    }
}
