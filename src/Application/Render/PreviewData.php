<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Domain\Attachment\Attachment;
use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\Attachment\SyncState;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Domain\Source\SourceStatus;
use SW\Calendar\Domain\Source\Visibility;

/**
 * Fixed preview datasets (SPECIFICATIONS.md §11.4): all-day, multi-day, recurring, without location, with and
 * without images/files, long texts and deliberately hostile external content. No provider requests.
 */
final class PreviewData
{
    /**
     * @return array{sources: array<int, Source>, items: list<array{occurrence: Occurrence, attachments: list<Attachment>}>}
     */
    public static function build(\DateTimeImmutable $now, \DateTimeZone $tz): array
    {
        $utc = new \DateTimeZone('UTC');
        $today = $now->setTimezone($tz)->setTime(0, 0);
        $sources = [
            1 => new Source(1, 1, 'preview-a', 'Gemeindekalender', '#2271b1', $tz->getName(), Visibility::Public, [], new SourceSettings(), 1, SourceStatus::Active),
            2 => new Source(2, 1, 'preview-b', 'Jugend', '#d63638', $tz->getName(), Visibility::Public, [], new SourceSettings(), 1, SourceStatus::Active),
        ];
        $at = static fn (int $days, int $hour, int $minute = 0): \DateTimeImmutable => $today->modify('+' . $days . ' days')->setTime($hour, $minute)->setTimezone($utc);
        $date = static fn (int $days): \DateTimeImmutable => new \DateTimeImmutable($today->modify('+' . $days . ' days')->format('Y-m-d') . ' 00:00:00', $utc);
        $make = static fn (int $id, int $source, string $title, \DateTimeImmutable $start, \DateTimeImmutable $end, bool $allDay, string $location = '', string $text = '', string $html = '', string $url = '', OccurrenceStatus $status = OccurrenceStatus::Confirmed): Occurrence => new Occurrence(
            $source,
            hash('sha256', 'preview' . $id),
            'preview-' . $id,
            null,
            null,
            $title,
            $html,
            $text,
            $location,
            $url,
            $start,
            $end,
            $allDay ? 'UTC' : $tz->getName(),
            $allDay,
            $status,
            [],
            $id,
        );
        $image = static fn (int $id, int $occurrence, string $name): Attachment => new Attachment($id, 1, $occurrence, 'ref' . $id, 'p', $name, 'image/png', 48213, null, null, 'preview', 'preview', true, PublicationState::PublicVerified, SyncState::Stored);
        $file = static fn (int $id, int $occurrence, string $name, string $mime, int $size): Attachment => new Attachment($id, 1, $occurrence, 'ref' . $id, 'p', $name, $mime, $size, null, null, 'preview', null, false, PublicationState::PublicVerified, SyncState::Stored);

        $items = [
            ['occurrence' => $make(101, 1, 'Gottesdienst', $at(1, 10), $at(1, 11, 30), false, 'Kirche St. Martin', "Mit Abendmahl und Kirchenchor.\nAnschließend Kirchenkaffee.", '<p>Mit <strong>Abendmahl</strong> und Kirchenchor.</p>', 'https://example.org/event/101'), 'attachments' => [$image(1, 101, 'Plakat.png'), $file(2, 101, 'Programm.pdf', 'application/pdf', 121340)]],
            ['occurrence' => $make(102, 2, 'Jugendfreizeit', $date(3), $date(6), true, 'Freizeitheim am See'), 'attachments' => [$file(3, 102, 'Packliste.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 24576)]],
            ['occurrence' => $make(103, 1, 'Bibelkreis', $at(4, 19, 30), $at(4, 21), false, '', 'Wöchentlicher Termin (Serie).'), 'attachments' => []],
            ['occurrence' => $make(104, 1, 'Bibelkreis', $at(11, 19, 30), $at(11, 21), false, '', 'Wöchentlicher Termin (Serie).', '', '', OccurrenceStatus::Tentative), 'attachments' => []],
            ['occurrence' => $make(105, 1, 'Feiertag', $date(8), $date(9), true), 'attachments' => []],
            ['occurrence' => $make(106, 2, str_repeat('Sehr langer Veranstaltungstitel mit vielen Wörtern ', 3), $at(9, 18), $at(10, 12), false, 'Gemeindehaus, Großer Saal, Hauptstraße 12, 12345 Musterstadt', str_repeat('Langer Beschreibungstext, der die Darstellung von umfangreichen Inhalten prüft. ', 12)), 'attachments' => []],
            ['occurrence' => $make(107, 2, '<script>alert("title")</script> Externe Daten', $at(12, 17), $at(12, 18), false, '" onmouseover="alert(1)', 'Text mit <b>Tags</b>', '<img src=x onerror=alert(1)><a href="javascript:alert(1)">Link</a><p>Erlaubter Absatz</p>', 'javascript:alert(1)'), 'attachments' => []],
        ];
        return ['sources' => $sources, 'items' => $items];
    }
}
