<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Local;

use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\OccurrenceStatus;

/** A concrete instance. UTC times; floating UTC-midnight dates for all-day events; exclusive end. */
final class LocalEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $calendarId,
        public readonly string $title,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
        public readonly string $timezone = 'UTC',
        public readonly bool $allDay = false,
        public readonly OccurrenceStatus $status = OccurrenceStatus::Confirmed,
        public readonly string $descriptionText = '',
        public readonly string $descriptionHtml = '',
        public readonly string $location = '',
        public readonly string $url = '',
        public readonly string $imageUrl = '',
        public readonly string $imageAlt = '',
    ) {
        if ($id === '' || strlen($id) > 190 || $calendarId === '' || $end <= $start) {
            throw new \InvalidArgumentException('Invalid local event identity or time range.');
        }
        new \DateTimeZone($timezone);
        if ($allDay && ($start->format('H:i:s') !== '00:00:00' || $end->format('H:i:s') !== '00:00:00' || $start->getOffset() !== 0 || $end->getOffset() !== 0)) {
            throw new \InvalidArgumentException('All-day dates must be floating UTC midnights.');
        }
    }

    public function occurrence(int $sourceId): Occurrence
    {
        $utc = new \DateTimeZone('UTC');
        return new Occurrence(
            $sourceId,
            Occurrence::keyFor($this->id, null),
            $this->id,
            null,
            null,
            $this->title,
            $this->descriptionHtml,
            $this->descriptionText,
            $this->location,
            self::safeUrl($this->url),
            $this->start->setTimezone($utc),
            $this->end->setTimezone($utc),
            $this->timezone,
            $this->allDay,
            $this->status,
            id: EventReference::local($sourceId, $this->id),
            imageUrl: self::safeUrl($this->imageUrl),
            imageAlt: $this->imageAlt,
        );
    }

    private static function safeUrl(string $url): string
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : '';
    }
}
