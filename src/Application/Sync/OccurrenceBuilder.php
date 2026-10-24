<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Sync;

use SW\Calendar\Application\Port\HtmlSanitizerInterface;
use SW\Calendar\Application\Render\Template\UrlPolicy;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Event\ProviderEvent;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Domain\Source\SourceSettings;

/**
 * Derives the complete occurrence snapshot of a source from its provider items. Deterministic: the same items and
 * window always give the same keys and hashes, so unchanged data causes no writes and no revision bump.
 */
final class OccurrenceBuilder
{
    public const MAX_OCCURRENCES_PER_SOURCE = 20000;

    /** @var list<string> Safe, aggregated warnings of the last build (shown in diagnostics). */
    public array $warnings = [];

    public function __construct(
        private readonly RecurrenceExpander $expander,
        private readonly HtmlSanitizerInterface $sanitizer,
        private readonly int $maxOccurrences = self::MAX_OCCURRENCES_PER_SOURCE,
    ) {
    }

    /**
     * @param iterable<ProviderEvent> $items
     * @return list<Occurrence>
     * @throws CalendarException LIMIT_EXCEEDED if the source exceeds the occurrence limit.
     */
    public function build(int $sourceId, iterable $items, SyncWindow $window, SourceSettings $settings): array
    {
        $this->warnings = [];
        /** @var array<string, array{masters: list<ProviderEvent>, overrides: list<ProviderEvent>, expanded: list<ProviderEvent>}> $series */
        $series = [];
        foreach ($items as $item) {
            $key = $item->seriesKey();
            $series[$key] ??= ['masters' => [], 'overrides' => [], 'expanded' => []];
            if ($item->expanded) {
                $series[$key]['expanded'][] = $item;
            } elseif ($item->recurrenceId !== null) {
                $series[$key]['overrides'][] = $item;
            } else {
                $series[$key]['masters'][] = $item;
            }
        }
        ksort($series, SORT_STRING);

        $occurrences = [];
        $skippedSeries = 0;
        foreach ($series as $seriesKey => $group) {
            foreach ($group['expanded'] as $instance) {
                $this->add($occurrences, $sourceId, (string) $seriesKey, $instance->recurrenceId ?? $instance->itemId, $instance, $instance->start, $instance->end, $settings, $window);
            }
            $master = $group['masters'][0] ?? null;
            if ($master === null) {
                // Orphan overrides (the master is outside the feed): show them as single events.
                foreach ($group['overrides'] as $override) {
                    $this->add($occurrences, $sourceId, (string) $seriesKey, $override->recurrenceId, $override, $override->start, $override->end, $settings, $window);
                }
                continue;
            }
            if ($master->status === OccurrenceStatus::Cancelled) {
                continue;
            }
            if (!$master->isRecurringMaster()) {
                $this->add($occurrences, $sourceId, (string) $seriesKey, null, $master, $master->start, $master->end, $settings, $window);
                continue;
            }
            try {
                $instances = $this->expander->expand($master, $group['overrides'], $window);
            } catch (CalendarException $e) {
                if ($e->errorCode === ErrorCode::LimitExceeded && str_contains($e->getMessage(), 'iteration')) {
                    throw $e;
                }
                // One broken series must not hide the rest of the calendar; it is reported, not silently dropped.
                $skippedSeries++;
                continue;
            }
            foreach ($instances as $instance) {
                $this->add($occurrences, $sourceId, (string) $seriesKey, $instance['recurrenceId'], $instance['event'], $instance['start'], $instance['end'], $settings, $window);
            }
            if (count($occurrences) > $this->maxOccurrences) {
                throw new CalendarException(ErrorCode::LimitExceeded, 'The source has more occurrences in its sync window than allowed.', null, ['limit' => $this->maxOccurrences]);
            }
        }
        if (count($occurrences) > $this->maxOccurrences) {
            throw new CalendarException(ErrorCode::LimitExceeded, 'The source has more occurrences in its sync window than allowed.', null, ['limit' => $this->maxOccurrences]);
        }
        if ($skippedSeries > 0) {
            $this->warnings[] = sprintf('%d recurring series could not be expanded.', $skippedSeries);
        }
        $list = array_values($occurrences);
        usort($list, static fn (Occurrence $a, Occurrence $b): int => [$a->startUtc, $a->occurrenceKey] <=> [$b->startUtc, $b->occurrenceKey]);
        return $list;
    }

    /**
     * @param array<string, Occurrence> $occurrences
     */
    private function add(
        array &$occurrences,
        int $sourceId,
        string $seriesKey,
        ?string $recurrenceId,
        ProviderEvent $event,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        SourceSettings $settings,
        SyncWindow $window,
    ): void {
        if ($event->status === OccurrenceStatus::Cancelled) {
            return;
        }
        if ($event->eventType !== null && in_array($event->eventType, $settings->excludedEventTypes, true)) {
            return;
        }
        if ($settings->excludePrivateEvents && $event->isPrivate) {
            return;
        }
        $utc = new \DateTimeZone('UTC');
        if ($event->allDay) {
            $startUtc = new \DateTimeImmutable($start->format('Y-m-d') . ' 00:00:00', $utc);
            $endUtc = new \DateTimeImmutable($end->format('Y-m-d') . ' 00:00:00', $utc);
            if ($endUtc <= $startUtc) {
                $endUtc = $startUtc->modify('+1 day');
            }
            // Floating dates: compare with the widened window.
            if ($startUtc >= $window->end->modify('+14 hours') || $endUtc <= $window->start->modify('-14 hours')) {
                return;
            }
        } else {
            $startUtc = $start->setTimezone($utc);
            $endUtc = $end->setTimezone($utc);
            if ($endUtc < $startUtc) {
                $endUtc = $startUtc;
            }
            // Zero length events still overlap the window if they start inside it.
            if ($startUtc >= $window->end || max($endUtc, $startUtc->modify('+1 second')) <= $window->start) {
                return;
            }
        }

        $key = Occurrence::keyFor($seriesKey, $recurrenceId);
        $html = $event->descriptionHtml !== '' ? $this->sanitizer->sanitizeDescription($event->descriptionHtml) : '';
        $text = $event->descriptionText !== '' ? $event->descriptionText : self::htmlToText($html);
        $occurrences[$key] = new Occurrence(
            $sourceId,
            $key,
            $event->itemId,
            $event->seriesId ?? ($recurrenceId !== null ? $seriesKey : null),
            $recurrenceId,
            self::clip($event->title, 1000),
            $html,
            self::clip($text, 20000),
            self::clip($event->location, 1000),
            UrlPolicy::isHttp($event->url) ? $event->url : '',
            $startUtc,
            $endUtc,
            $event->allDay ? 'UTC' : $start->getTimezone()->getName(),
            $event->allDay,
            $event->status,
            $event->attachments,
        );
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('~<(br|/p|/div|/li|/h[1-6])\b[^>]*>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+/u", ' ', $text);
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private static function clip(string $value, int $max): string
    {
        $value = trim($value);
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
