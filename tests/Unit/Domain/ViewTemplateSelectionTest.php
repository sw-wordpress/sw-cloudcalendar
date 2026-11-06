<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Domain\View\CalendarView;
use SW\Calendar\Domain\View\DisplayMode;

/**
 * Templates may be chosen per display mode; a mode without its own choice keeps the template of the whole view
 * (and, when that is empty as well, the preset of the mode).
 */
final class ViewTemplateSelectionTest extends TestCase
{
    private static function view(
        ?int $eventTemplateId,
        ?int $containerTemplateId,
        array $eventByMode = [],
        array $containerByMode = [],
    ): CalendarView {
        return new CalendarView(
            1,
            'Termine',
            true,
            [3],
            [],
            DisplayMode::List,
            [DisplayMode::List, DisplayMode::Cards, DisplayMode::Month],
            $containerTemplateId,
            $eventTemplateId,
            null,
            [],
            '',
            '',
            [],
            1,
            true,
            '',
            '',
            true,
            $containerByMode,
            $eventByMode,
        );
    }

    public function testModeChoiceWinsAndOtherModesKeepThePreset(): void
    {
        $view = self::view(null, null, ['cards' => 42], ['cards' => 7]);
        self::assertSame(42, $view->eventTemplateFor(DisplayMode::Cards));
        self::assertSame(7, $view->containerTemplateFor(DisplayMode::Cards));
        foreach ([DisplayMode::List, DisplayMode::Month] as $mode) {
            self::assertNull($view->eventTemplateFor($mode), 'Null lets the preset of the mode step in');
            self::assertNull($view->containerTemplateFor($mode));
        }
    }

    public function testViewWideTemplateStaysForModesWithoutTheirOwnChoice(): void
    {
        $view = self::view(9, 11, ['cards' => 42]);
        self::assertSame(42, $view->eventTemplateFor(DisplayMode::Cards));
        self::assertSame(9, $view->eventTemplateFor(DisplayMode::Month));
        self::assertSame(9, $view->eventTemplateFor(DisplayMode::List));
        self::assertSame(11, $view->containerTemplateFor(DisplayMode::Cards), 'Only the event template differs');
    }

    public function testViewWithoutModeChoicesBehavesLikeBefore(): void
    {
        $view = self::view(9, 11);
        foreach (DisplayMode::cases() as $mode) {
            self::assertSame(9, $view->eventTemplateFor($mode));
            self::assertSame(11, $view->containerTemplateFor($mode));
        }
    }

    public function testUnknownModeKeysAreNotUsed(): void
    {
        $view = self::view(null, null, ['week' => 42]);
        foreach (DisplayMode::cases() as $mode) {
            self::assertNull($view->eventTemplateFor($mode));
        }
    }

    public function testAllowedTemplateStaysIndependentOfTheMode(): void
    {
        $view = new CalendarView(
            1,
            'Termine',
            true,
            [3],
            [],
            DisplayMode::List,
            [DisplayMode::Cards],
            null,
            9,
            null,
            [12],
            '',
            '',
            [],
            1,
            true,
            '',
            '',
            true,
            [],
            ['cards' => 42],
        );
        self::assertTrue($view->allowsTemplate(12));
        self::assertTrue($view->allowsTemplate(9), 'The view wide template is always allowed');
        self::assertFalse($view->allowsTemplate(42), 'A mode default is no permission for the template parameter');
    }
}
