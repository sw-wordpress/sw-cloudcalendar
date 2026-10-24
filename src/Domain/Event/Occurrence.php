<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Event;

use SW\Calendar\Domain\Provider\ProviderAttachmentDefinition;

/**
 * One concrete instance of an appointment as stored locally (SPECIFICATIONS.md §3.3).
 *
 * `startUtc`/`endUtc` are UTC for timed events. All-day occurrences store their floating dates as 00:00 "UTC"
 * (see docs/decisions.md D-08); `end` is exclusive in both cases.
 */
final class Occurrence
{
    /**
     * @param list<ProviderAttachmentDefinition> $attachments
     */
    public function __construct(
        public readonly int $sourceId,
        public readonly string $occurrenceKey,
        public readonly string $providerEventId,
        public readonly ?string $seriesId,
        public readonly ?string $recurrenceId,
        public readonly string $title,
        public readonly string $descriptionHtml,
        public readonly string $descriptionText,
        public readonly string $location,
        public readonly string $externalUrl,
        public readonly \DateTimeImmutable $startUtc,
        public readonly \DateTimeImmutable $endUtc,
        public readonly string $timezone,
        public readonly bool $allDay,
        public readonly OccurrenceStatus $status,
        public readonly array $attachments = [],
        public readonly int $id = 0,
    ) {
    }

    /**
     * Stable identity: the same source event and the same recurrence instance always produce the same key, even if
     * the instance is moved to another time.
     */
    public static function keyFor(string $seriesKey, ?string $recurrenceId): string
    {
        return hash('sha256', $seriesKey . "\0" . ($recurrenceId ?? ''));
    }

    /** Hash of everything that is displayed or influences attachments; used to detect real changes. */
    public function payloadHash(): string
    {
        return hash('sha256', (string) json_encode([
            $this->providerEventId,
            $this->seriesId,
            $this->recurrenceId,
            $this->title,
            $this->descriptionHtml,
            $this->descriptionText,
            $this->location,
            $this->externalUrl,
            $this->startUtc->format('Y-m-d H:i:s'),
            $this->endUtc->format('Y-m-d H:i:s'),
            $this->timezone,
            $this->allDay,
            $this->status->value,
            array_map(static fn (ProviderAttachmentDefinition $a): array => $a->toArray(), $this->attachments),
        ], JSON_UNESCAPED_UNICODE));
    }
}
