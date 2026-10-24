<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Provider\Ics;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Event\ProviderEvent;
use SW\Calendar\Domain\Provider\ProviderAttachmentDefinition;

/**
 * RFC 5545 feed parsing with sabre/vobject (UID, DTSTART, DTEND/DURATION, RRULE, RDATE, EXDATE, RECURRENCE-ID,
 * STATUS, TZID/VTIMEZONE, DESCRIPTION, LOCATION, URL, ATTACH). Produces provider items; expansion happens later.
 */
final class IcsParser
{
    public const MAX_EVENTS = 20000;

    public function __construct(private readonly int $maxEvents = self::MAX_EVENTS)
    {
    }

    /**
     * @return array{name: string|null, timezone: string|null, items: list<ProviderEvent>}
     * @throws CalendarException
     */
    public function parse(string $data, \DateTimeZone $defaultTz): array
    {
        $data = self::toUtf8($data);
        try {
            $calendar = Reader::read($data, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (\Throwable $e) {
            throw new CalendarException(ErrorCode::FetchFailed, 'The ICS data could not be parsed.', null, [], $e);
        }
        if (!$calendar instanceof VCalendar) {
            throw new CalendarException(ErrorCode::FetchFailed, 'The response is not an iCalendar feed.');
        }
        $name = isset($calendar->{'X-WR-CALNAME'}) ? trim((string) $calendar->{'X-WR-CALNAME'}) : null;
        $tzName = isset($calendar->{'X-WR-TIMEZONE'}) ? trim((string) $calendar->{'X-WR-TIMEZONE'}) : null;
        if ($tzName !== null && !in_array($tzName, \DateTimeZone::listIdentifiers(), true)) {
            $tzName = null;
        }
        $floatingTz = $tzName !== null ? new \DateTimeZone($tzName) : $defaultTz;

        $items = [];
        $count = 0;
        foreach ($calendar->select('VEVENT') as $vevent) {
            if (!$vevent instanceof VEvent) {
                continue;
            }
            if (++$count > $this->maxEvents) {
                throw new CalendarException(ErrorCode::LimitExceeded, 'The feed contains too many events.', null, ['limit' => $this->maxEvents]);
            }
            $item = $this->event($vevent, $floatingTz);
            if ($item !== null) {
                $items[$item->itemId] = $item;
            }
        }
        return ['name' => $name !== '' ? $name : null, 'timezone' => $tzName, 'items' => array_values($items)];
    }

    private function event(VEvent $vevent, \DateTimeZone $floatingTz): ?ProviderEvent
    {
        $dtstart = self::property($vevent, 'DTSTART');
        if (!$dtstart instanceof DateTimeProperty) {
            return null;
        }
        $allDay = !$dtstart->hasTime();
        try {
            $start = $this->time($dtstart, $allDay, $floatingTz);
            $end = $this->endOf($vevent, $start, $allDay, $floatingTz);
        } catch (\Throwable) {
            return null;
        }

        $uid = isset($vevent->UID) ? trim((string) $vevent->UID) : '';
        if ($uid === '') {
            $uid = 'nouid-' . hash('sha256', $start->format(DATE_ATOM) . '|' . (string) self::property($vevent, 'SUMMARY'));
        }
        $recurrenceId = null;
        if (isset($vevent->{'RECURRENCE-ID'}) && $vevent->{'RECURRENCE-ID'} instanceof DateTimeProperty) {
            $rid = $this->time($vevent->{'RECURRENCE-ID'}, !$vevent->{'RECURRENCE-ID'}->hasTime(), $floatingTz);
            $recurrenceId = $allDay || !$vevent->{'RECURRENCE-ID'}->hasTime()
                ? $rid->format('Y-m-d')
                : $rid->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        $itemId = self::itemId($uid, $recurrenceId);

        $rrule = isset($vevent->RRULE) ? (string) $vevent->RRULE->getValue() : null;
        $exdates = [];
        foreach ($vevent->select('EXDATE') as $exdate) {
            if ($exdate instanceof DateTimeProperty) {
                foreach ($exdate->getDateTimes($floatingTz) as $dt) {
                    $exdates[] = $allDay ? self::floating($dt) : \DateTimeImmutable::createFromInterface($dt);
                }
            }
        }
        $rdates = [];
        foreach ($vevent->select('RDATE') as $rdate) {
            if ($rdate instanceof DateTimeProperty) {
                try {
                    foreach ($rdate->getDateTimes($floatingTz) as $dt) {
                        $rdates[] = $allDay ? self::floating($dt) : \DateTimeImmutable::createFromInterface($dt);
                    }
                } catch (\Throwable) {
                    // RDATE;VALUE=PERIOD is not supported; the remaining instances stay valid.
                }
            }
        }

        $html = '';
        foreach ($vevent->select('X-ALT-DESC') as $alt) {
            if (strtolower((string) ($alt['FMTTYPE'] ?? '')) === 'text/html') {
                $html = (string) $alt;
            }
        }
        $class = strtoupper(trim((string) ($vevent->CLASS ?? '')));
        $updated = null;
        foreach (['LAST-MODIFIED', 'DTSTAMP'] as $property) {
            if ($updated === null && isset($vevent->{$property}) && $vevent->{$property} instanceof DateTimeProperty) {
                $updated = \DateTimeImmutable::createFromInterface($vevent->{$property}->getDateTime(new \DateTimeZone('UTC')));
            }
        }

        return new ProviderEvent(
            $itemId,
            $uid,
            $recurrenceId,
            trim((string) ($vevent->SUMMARY ?? '')),
            $html,
            trim((string) ($vevent->DESCRIPTION ?? '')),
            trim((string) ($vevent->LOCATION ?? '')),
            trim((string) ($vevent->URL ?? '')),
            $start,
            $end,
            $allDay,
            $allDay ? 'UTC' : $start->getTimezone()->getName(),
            OccurrenceStatus::fromProvider(isset($vevent->STATUS) ? (string) $vevent->STATUS : null),
            $recurrenceId === null && $rrule !== null && $rrule !== '' ? $rrule : null,
            $recurrenceId === null ? $exdates : [],
            $recurrenceId === null ? $rdates : [],
            null,
            $class === 'PRIVATE' || $class === 'CONFIDENTIAL',
            $this->attachments($vevent, $uid),
            $updated,
        );
    }

    /**
     * @return list<ProviderAttachmentDefinition>
     */
    private function attachments(VEvent $vevent, string $uid): array
    {
        $result = [];
        foreach ($vevent->select('ATTACH') as $attach) {
            if (!$attach instanceof Property) {
                continue;
            }
            $mime = strtolower(trim(self::parameter($attach, 'FMTTYPE') ?: 'application/octet-stream'));
            $filename = trim(self::parameter($attach, 'FILENAME') ?: self::parameter($attach, 'X-FILENAME') ?: self::parameter($attach, 'X-APPLE-FILENAME'));
            $isBinary = strtoupper(self::parameter($attach, 'VALUE')) === 'BINARY' || strtoupper(self::parameter($attach, 'ENCODING')) === 'BASE64';
            if ($isBinary) {
                $raw = $attach->getValue();
                $hash = hash('sha256', (string) $raw);
                $result[] = new ProviderAttachmentDefinition(
                    ProviderAttachmentDefinition::KIND_INLINE,
                    'inline:' . hash('sha256', $uid) . ':' . $hash,
                    $filename !== '' ? $filename : 'attachment',
                    $mime,
                    strlen((string) $raw),
                    $hash,
                );
                continue;
            }
            $url = trim((string) $attach->getValue());
            if (preg_match('~^https?://~i', $url) !== 1) {
                continue;
            }
            if ($filename === '') {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $filename = rawurldecode(basename($path)) ?: 'attachment';
            }
            $result[] = new ProviderAttachmentDefinition(ProviderAttachmentDefinition::KIND_URL, $url, $filename, $mime);
        }
        return $result;
    }

    /**
     * Finds the decoded content of an inline attachment by its reference.
     */
    public function inlineAttachment(string $data, string $reference): ?string
    {
        $calendar = Reader::read(self::toUtf8($data), Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        if (!$calendar instanceof VCalendar) {
            return null;
        }
        foreach ($calendar->select('VEVENT') as $vevent) {
            foreach ($vevent->select('ATTACH') as $attach) {
                $raw = (string) $attach->getValue();
                $uid = trim((string) ($vevent->UID ?? ''));
                if ('inline:' . hash('sha256', $uid) . ':' . hash('sha256', $raw) === $reference) {
                    return $raw;
                }
            }
        }
        return null;
    }

    /** First property of a name (sabre exposes properties as magic members, invisible to static analysis). */
    private static function property(VEvent $vevent, string $name): ?Property
    {
        $found = $vevent->select($name);
        $first = reset($found);
        return $first instanceof Property ? $first : null;
    }

    private static function parameter(Property $property, string $name): string
    {
        $parameter = $property->offsetGet($name);
        return $parameter instanceof \Sabre\VObject\Parameter ? (string) $parameter->getValue() : '';
    }

    private function time(DateTimeProperty $property, bool $allDay, \DateTimeZone $floatingTz): \DateTimeImmutable
    {
        $dt = $property->getDateTime($floatingTz);
        return $allDay ? self::floating($dt) : \DateTimeImmutable::createFromInterface($dt);
    }

    private function endOf(VEvent $vevent, \DateTimeImmutable $start, bool $allDay, \DateTimeZone $floatingTz): \DateTimeImmutable
    {
        if (isset($vevent->DTEND) && $vevent->DTEND instanceof DateTimeProperty) {
            $end = $this->time($vevent->DTEND, $allDay, $floatingTz);
            if (!$allDay) {
                $end = $end->setTimezone($start->getTimezone());
            }
            return $end > $start ? $end : ($allDay ? $start->modify('+1 day') : $start);
        }
        if (isset($vevent->DURATION)) {
            $interval = $vevent->DURATION->getDateInterval();
            return $start->add($interval);
        }
        return $allDay ? $start->modify('+1 day') : $start;
    }

    private static function floating(\DateTimeInterface $dt): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dt->format('Y-m-d') . ' 00:00:00', new \DateTimeZone('UTC'));
    }

    public static function itemId(string $uid, ?string $recurrenceId): string
    {
        $id = $uid . ($recurrenceId !== null ? '#' . $recurrenceId : '');
        return strlen($id) > 400 ? 'h:' . hash('sha256', $id) : $id;
    }

    /**
     * Feeds are UTF-8 by RFC; legacy feeds in Windows-1252/ISO-8859-1 are converted instead of being rejected.
     */
    public static function toUtf8(string $data): string
    {
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            $data = substr($data, 3);
        }
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }
        return mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
    }
}
