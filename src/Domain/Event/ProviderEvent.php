<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Event;

use SW\Calendar\Domain\Provider\ProviderAttachmentDefinition;

/**
 * Provider independent representation of one provider item: a single event, a recurring master, an overridden
 * instance (exception) or an already expanded instance (Microsoft calendarView).
 *
 * Time contract:
 * - timed events: `start`/`end` carry their real time zone (IANA), `end` is exclusive.
 * - all-day events: `start`/`end` are dates at 00:00 in UTC used as floating dates, `end` exclusive.
 * - `recurrenceId` is the ORIGINAL start of an overridden instance: `Y-m-d\TH:i:s\Z` (UTC) or `Y-m-d` (all-day).
 */
final class ProviderEvent
{
    /**
     * @param list<\DateTimeImmutable>            $exdates
     * @param list<\DateTimeImmutable>            $rdates
     * @param list<ProviderAttachmentDefinition> $attachments
     */
    public function __construct(
        public readonly string $itemId,
        public readonly ?string $seriesId,
        public readonly ?string $recurrenceId,
        public readonly string $title,
        public readonly string $descriptionHtml,
        public readonly string $descriptionText,
        public readonly string $location,
        public readonly string $url,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
        public readonly bool $allDay,
        public readonly string $timezone,
        public readonly OccurrenceStatus $status,
        public readonly ?string $rrule = null,
        public readonly array $exdates = [],
        public readonly array $rdates = [],
        public readonly ?string $eventType = null,
        public readonly bool $isPrivate = false,
        public readonly array $attachments = [],
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly bool $expanded = false,
    ) {
    }

    public function isRecurringMaster(): bool
    {
        return $this->recurrenceId === null && ($this->rrule !== null || $this->rdates !== []);
    }

    public function isOverride(): bool
    {
        return $this->recurrenceId !== null && !$this->expanded;
    }

    /** Identity of the series this item belongs to (its own id for single events and masters). */
    public function seriesKey(): string
    {
        return $this->seriesId ?? $this->itemId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $format = static fn (\DateTimeImmutable $d): string => $d->format(DATE_ATOM) . '|' . $d->getTimezone()->getName();
        return [
            'item_id' => $this->itemId,
            'series_id' => $this->seriesId,
            'recurrence_id' => $this->recurrenceId,
            'title' => $this->title,
            'description_html' => $this->descriptionHtml,
            'description_text' => $this->descriptionText,
            'location' => $this->location,
            'url' => $this->url,
            'start' => $format($this->start),
            'end' => $format($this->end),
            'all_day' => $this->allDay,
            'timezone' => $this->timezone,
            'status' => $this->status->value,
            'rrule' => $this->rrule,
            'exdates' => array_map($format, $this->exdates),
            'rdates' => array_map($format, $this->rdates),
            'event_type' => $this->eventType,
            'is_private' => $this->isPrivate,
            'attachments' => array_map(static fn (ProviderAttachmentDefinition $a): array => $a->toArray(), $this->attachments),
            'updated_at' => $this->updatedAt?->format(DATE_ATOM),
            'expanded' => $this->expanded,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $parse = static function (string $value): \DateTimeImmutable {
            [$time, $zone] = array_pad(explode('|', $value, 2), 2, 'UTC');
            return (new \DateTimeImmutable($time))->setTimezone(new \DateTimeZone($zone !== '' ? $zone : 'UTC'));
        };
        return new self(
            (string) $data['item_id'],
            isset($data['series_id']) ? (string) $data['series_id'] : null,
            isset($data['recurrence_id']) ? (string) $data['recurrence_id'] : null,
            (string) ($data['title'] ?? ''),
            (string) ($data['description_html'] ?? ''),
            (string) ($data['description_text'] ?? ''),
            (string) ($data['location'] ?? ''),
            (string) ($data['url'] ?? ''),
            $parse((string) $data['start']),
            $parse((string) $data['end']),
            (bool) ($data['all_day'] ?? false),
            (string) ($data['timezone'] ?? 'UTC'),
            OccurrenceStatus::from((string) ($data['status'] ?? 'confirmed')),
            isset($data['rrule']) ? (string) $data['rrule'] : null,
            array_map($parse, array_values((array) ($data['exdates'] ?? []))),
            array_map($parse, array_values((array) ($data['rdates'] ?? []))),
            isset($data['event_type']) ? (string) $data['event_type'] : null,
            (bool) ($data['is_private'] ?? false),
            array_map(
                static fn (array $a): ProviderAttachmentDefinition => ProviderAttachmentDefinition::fromArray($a),
                array_values(array_filter((array) ($data['attachments'] ?? []), 'is_array'))
            ),
            isset($data['updated_at']) ? new \DateTimeImmutable((string) $data['updated_at']) : null,
            (bool) ($data['expanded'] ?? false),
        );
    }
}
