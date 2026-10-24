<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Sync\OccurrenceBuilder;
use SW\Calendar\Application\Sync\RecurrenceExpander;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Provider\ProviderAttachmentDefinition;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Infrastructure\Provider\Ics\IcsParser;
use SW\Calendar\Tests\Support\PlainSanitizer;

final class IcsParserTest extends TestCase
{
    /**
     * @return list<Occurrence>
     */
    private function occurrences(string $from, string $to): array
    {
        $parsed = (new IcsParser())->parse((string) file_get_contents(__DIR__ . '/../../Fixtures/ics/parish.ics'), new \DateTimeZone('UTC'));
        $window = new SyncWindow(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
        return (new OccurrenceBuilder(new RecurrenceExpander(), new PlainSanitizer()))->build(7, $parsed['items'], $window, new SourceSettings());
    }

    public function testFeedMetadataAndItems(): void
    {
        $parsed = (new IcsParser())->parse((string) file_get_contents(__DIR__ . '/../../Fixtures/ics/parish.ics'), new \DateTimeZone('UTC'));
        self::assertSame('Gemeinde', $parsed['name']);
        self::assertSame('Europe/Berlin', $parsed['timezone']);
        self::assertCount(6, $parsed['items']);
        $master = $parsed['items'][0];
        self::assertSame('weekly-1@test', $master->seriesKey());
        self::assertSame("Mit Abendmahl\nund Chor", $master->descriptionText);
        self::assertCount(2, $master->attachments);
        self::assertSame(ProviderAttachmentDefinition::KIND_URL, $master->attachments[0]->kind);
        self::assertSame('Programm.pdf', $master->attachments[0]->filename);
        self::assertSame(ProviderAttachmentDefinition::KIND_INLINE, $master->attachments[1]->kind);
        self::assertSame(10, $master->attachments[1]->sizeBytes);
    }

    public function testWindowsTimezoneOverrideCancellationExdateAndUntil(): void
    {
        $list = array_values(array_filter($this->occurrences('2026-10-01T00:00:00Z', '2026-12-01T00:00:00Z'), static fn (Occurrence $o): bool => str_contains($o->title, 'ottesdienst')));
        $actual = array_map(static fn (Occurrence $o): string => $o->startUtc->format('m-d H:i') . ' ' . $o->title, $list);
        self::assertSame([
            '10-18 08:00 Gottesdienst',
            '10-25 16:00 Abendgottesdienst',
            '11-15 09:00 Gottesdienst',
        ], $actual, '11-01 excluded (EXDATE), 11-08 cancelled override, UNTIL respected, DST handled');
    }

    public function testFloatingTimeUsesCalendarTimezoneAndDuration(): void
    {
        $concert = array_values(array_filter($this->occurrences('2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'), static fn (Occurrence $o): bool => $o->title === 'Konzert'))[0];
        self::assertSame('2026-11-20 18:00', $concert->startUtc->format('Y-m-d H:i'));
        self::assertSame('2026-11-20 20:00', $concert->endUtc->format('Y-m-d H:i'));
        self::assertSame(OccurrenceStatus::Tentative, $concert->status);
        self::assertSame('https://example.org/konzert', $concert->externalUrl);
    }

    public function testInfiniteRuleAndAllDay(): void
    {
        $list = $this->occurrences('2026-12-20T00:00:00Z', '2027-01-01T00:00:00Z');
        $prayers = array_filter($list, static fn (Occurrence $o): bool => $o->title === 'Morgengebet');
        self::assertCount(12, $prayers);
        $xmas = array_values(array_filter($list, static fn (Occurrence $o): bool => $o->title === 'Weihnachten'))[0];
        self::assertTrue($xmas->allDay);
        self::assertSame('2026-12-27', $xmas->endUtc->format('Y-m-d'));
    }

    public function testInvalidAndLegacyEncodedData(): void
    {
        $this->expectException(CalendarException::class);
        (new IcsParser())->parse('<html>not a calendar</html>', new \DateTimeZone('UTC'));
    }

    public function testWindows1252IsConverted(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:20261001T100000Z\r\nSUMMARY:Gr\xFC\xDFe\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $parsed = (new IcsParser())->parse($ics, new \DateTimeZone('UTC'));
        self::assertSame('Grüße', $parsed['items'][0]->title);
    }

    public function testTooManyEvents(): void
    {
        $this->expectException(CalendarException::class);
        $events = '';
        for ($i = 0; $i < 3; $i++) {
            $events .= "BEGIN:VEVENT\r\nUID:$i\r\nDTSTART:20261001T100000Z\r\nEND:VEVENT\r\n";
        }
        (new IcsParser(2))->parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n{$events}END:VCALENDAR\r\n", new \DateTimeZone('UTC'));
    }
}
