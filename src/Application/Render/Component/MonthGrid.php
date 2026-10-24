<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Component;

use SW\Calendar\Application\Port\DateFormatterInterface;
use SW\Calendar\Application\Port\UrlGeneratorInterface;
use SW\Calendar\Application\Render\EventValues;
use SW\Calendar\Application\Render\QueryRange;
use SW\Calendar\Application\Render\RenderLabels;
use SW\Calendar\Application\Render\Template\TemplateCompiler;
use SW\Calendar\Application\Render\Template\TrustedHtml;
use SW\Calendar\Domain\Event\Occurrence;

/**
 * Accessible month table: caption, column headers with full weekday names, `<time>` per day, events as links.
 * Colour is always accompanied by text (title/time). On small screens CSS turns the table into an agenda.
 */
final class MonthGrid
{
    public function __construct(
        private readonly DateFormatterInterface $dates,
        private readonly UrlGeneratorInterface $urls,
        private readonly RenderLabels $labels,
    ) {
    }

    /**
     * @param list<array{occurrence: Occurrence, html: string}> $events Rendered event templates in display order.
     */
    public function render(QueryRange $range, array $events, \DateTimeZone $tz, \DateTimeImmutable $now): TrustedHtml
    {
        $month = $range->month ?? new \DateTimeImmutable($range->floatingStart->format('Y-m-01'), $tz);
        $first = new \DateTimeImmutable($range->floatingStart->format('Y-m-d') . ' 00:00:00', $tz);
        $endExclusive = new \DateTimeImmutable($range->floatingEnd->format('Y-m-d') . ' 00:00:00', $tz);
        $today = $now->setTimezone($tz)->format('Y-m-d');

        $byDay = [];
        foreach ($events as $event) {
            [$start, , $endInclusive] = EventValues::localTimes($event['occurrence'], $tz);
            $day = max($start->setTime(0, 0), $first);
            $last = min($endInclusive->setTime(0, 0), $endExclusive->modify('-1 day'));
            for ($guard = 0; $day <= $last && $guard < 42; $guard++) {
                $byDay[$day->format('Y-m-d')][] = $event;
                $day = $day->modify('+1 day');
            }
        }

        $caption = $this->dates->format($month, $tz, 'F Y');
        $html = '<table class="swc-month"><caption class="swc-month__caption">' . $this->e($caption) . '</caption><thead><tr>';
        for ($i = 0, $day = $first; $i < 7; $i++, $day = $day->modify('+1 day')) {
            $html .= '<th scope="col" class="swc-month__weekday"><abbr title="' . $this->e($this->dates->format($day, $tz, 'l')) . '">'
                . $this->e($this->dates->format($day, $tz, 'D')) . '</abbr></th>';
        }
        $html .= '</tr></thead><tbody>';

        $day = $first;
        for ($guard = 0; $day < $endExclusive && $guard < 42; $guard++) {
            if ($guard % 7 === 0) {
                $html .= $guard === 0 ? '<tr>' : '</tr><tr>';
            }
            $key = $day->format('Y-m-d');
            $classes = ['swc-month__day'];
            if ($day->format('Y-m') !== $month->format('Y-m')) {
                $classes[] = 'is-other-month';
            }
            if ($key === $today) {
                $classes[] = 'is-today';
            }
            $items = $byDay[$key] ?? [];
            if ($items !== []) {
                $classes[] = 'has-events';
            }
            $label = $this->dates->format($day, $tz, 'l, j. F Y');
            $html .= '<td class="' . implode(' ', $classes) . '" data-date="' . $key . '">'
                . '<div class="swc-month__date"><time datetime="' . $key . '" aria-label="' . $this->e($label) . '">'
                . $this->e($this->dates->format($day, $tz, 'j')) . '</time>'
                . ($key === $today ? ' <span class="swc-month__today">' . $this->e($this->labels->today) . '</span>' : '')
                . '</div>';
            if ($items !== []) {
                $html .= '<ul class="swc-month__events">';
                foreach ($items as $item) {
                    $html .= '<li class="swc-month__event" data-swc-event="' . $item['occurrence']->id . '">' . $item['html'] . '</li>';
                }
                $html .= '</ul>';
            }
            $html .= '</td>';
            $day = $day->modify('+1 day');
        }
        return new TrustedHtml($html . '</tr></tbody></table>');
    }

    public function navigation(int $viewId, \DateTimeImmutable $month, \DateTimeZone $tz): TrustedHtml
    {
        $previous = $month->modify('first day of previous month');
        $next = $month->modify('first day of next month');
        $html = '<nav class="swc-nav" aria-label="' . $this->e($this->labels->calendarNavigation) . '">'
            . $this->link($viewId, $previous, $tz, 'swc-nav__prev', $this->labels->previousMonth, '‹')
            . '<span class="swc-nav__label" aria-live="polite">' . $this->e($this->dates->format($month, $tz, 'F Y')) . '</span>'
            . $this->link($viewId, $next, $tz, 'swc-nav__next', $this->labels->nextMonth, '›')
            . '</nav>';
        return new TrustedHtml($html);
    }

    private function link(int $viewId, \DateTimeImmutable $month, \DateTimeZone $tz, string $class, string $label, string $arrow): string
    {
        $value = $month->format('Y-m');
        return '<a class="' . $class . '" href="' . $this->e($this->urls->month($viewId, $value)) . '" data-swc-month="' . $value . '" rel="nofollow">'
            . '<span aria-hidden="true">' . $arrow . '</span> <span class="swc-nav__text">' . $this->e($label)
            . '<span class="swc-screen-reader-text">: ' . $this->e($this->dates->format($month, $tz, 'F Y')) . '</span></span></a>';
    }

    private function e(string $value): string
    {
        return TemplateCompiler::escape($value);
    }
}
