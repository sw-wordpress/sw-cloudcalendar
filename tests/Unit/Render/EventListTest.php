<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Local\LocalEvent;
use SW\Calendar\Application\Port\DateFormatterInterface;
use SW\Calendar\Application\Render\Component\EventList;
use SW\Calendar\Application\Render\RenderLabels;
use SW\Calendar\Application\Render\Template\TemplateCompiler;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Template\TemplateKind;
use SW\Calendar\Domain\View\DisplayMode;

final class EventListTest extends TestCase
{
    public function testFloatingDatesAndTimedDatesUseTheDisplayedMonth(): void
    {
        $dates = new class implements DateFormatterInterface {
            public function format(\DateTimeInterface $time, \DateTimeZone $tz, string $format): string
            {
                return $time->format($format) . ' <unsafe>';
            }
            public function defaultDateFormat(): string
            {
                return 'Y-m-d';
            }
            public function defaultTimeFormat(): string
            {
                return 'H:i';
            }
        };
        $list = new EventList($dates, new RenderLabels());
        $start = new \DateTimeImmutable('2026-12-01T00:00:00Z');
        $timed = (new LocalEvent('a', 'cal', 'Timed', $start, $start->modify('+1 hour')))->occurrence(1);
        $floating = (new LocalEvent('b', 'cal', 'All day', $start, $start->modify('+1 day'), allDay: true, status: OccurrenceStatus::Tentative))->occurrence(1);
        $rows = [
            ['occurrence' => $timed, 'html' => '<article>Timed</article>', 'color' => '#123456'],
            ['occurrence' => $floating, 'html' => '<article>All day</article>', 'color' => 'red;bad'],
        ];
        $html = (string) $list->render($rows, DisplayMode::Cards, new \DateTimeZone('America/Los_Angeles'));
        self::assertStringContainsString('data-swc-event-month="2026-11"', $html);
        self::assertStringContainsString('data-swc-event-month="2026-12"', $html);
        self::assertSame(2, substr_count($html, '<ul class="swc-events'));
        self::assertStringContainsString('November 2026 &lt;unsafe&gt;', $html);
        self::assertStringContainsString(' is-tentative', $html);
        self::assertStringContainsString('--swc-event-color:#3366cc', $html);
        self::assertStringNotContainsString('red;bad', $html);
        self::assertStringNotContainsString('<unsafe>', $html);
        self::assertStringContainsString('swc-empty', (string) $list->render([], DisplayMode::List));
    }

    public function testMonthGroupsAreOnlyAvailableInContainerTemplates(): void
    {
        $compiler = new TemplateCompiler();
        self::assertSame([], $compiler->validate('{{calendar.events_by_month}}', TemplateKind::Container));
        self::assertNotSame([], $compiler->validate('{{calendar.events_by_month}}', TemplateKind::Event));
        self::assertSame([], $compiler->validate('{{event.weekday_short}}', TemplateKind::Event));
    }
}
