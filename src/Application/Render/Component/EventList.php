<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Component;

use SW\Calendar\Application\Port\DateFormatterInterface;
use SW\Calendar\Application\Render\EventValues;
use SW\Calendar\Application\Render\RenderLabels;
use SW\Calendar\Application\Render\Template\TemplateCompiler;
use SW\Calendar\Application\Render\Template\TrustedHtml;
use SW\Calendar\Application\Render\ViewRenderer;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\View\DisplayMode;

final class EventList
{
    public function __construct(private readonly DateFormatterInterface $dates, private readonly RenderLabels $labels)
    {
    }

    /**
     * A timezone enables month groups based on each event's displayed start date, including floating all-day dates.
     *
     * @param list<array{occurrence: Occurrence, html: string, color?: string}> $rendered
     */
    public function render(array $rendered, DisplayMode $mode, ?\DateTimeZone $groupTimezone = null): TrustedHtml
    {
        if ($rendered === []) {
            return new TrustedHtml('<p class="swc-empty">' . TemplateCompiler::escape($this->labels->noEvents) . '</p>');
        }
        $tag = $mode === DisplayMode::Cards ? 'ul' : 'ol';
        $openList = '<' . $tag . ' class="swc-events swc-events--' . $mode->value . '">';
        $html = $groupTimezone === null ? $openList : '';
        $month = '';
        foreach ($rendered as $item) {
            $occurrence = $item['occurrence'];
            if ($groupTimezone !== null) {
                [$start] = EventValues::localTimes($occurrence, $groupTimezone);
                $key = $start->format('Y-m');
                if ($key !== $month) {
                    if ($month !== '') {
                        $html .= '</' . $tag . '></section>';
                    }
                    $month = $key;
                    $html .= '<section class="swc-month-group" data-swc-event-month="' . $key . '"><h2 class="swc-month-group__title">'
                        . TemplateCompiler::escape($this->dates->format($start, $groupTimezone, 'F Y')) . '</h2>' . $openList;
                }
            }
            $html .= '<li class="swc-events__item' . ($occurrence->status === OccurrenceStatus::Tentative ? ' is-tentative' : '') . '"'
                . ' style="--swc-event-color:' . ViewRenderer::color($item['color'] ?? '') . '" data-swc-event="' . TemplateCompiler::escape((string) $occurrence->id) . '" data-swc-source="' . $occurrence->sourceId . '">' . $item['html'] . '</li>';
        }
        return new TrustedHtml($html . '</' . $tag . '>' . ($groupTimezone === null ? '' : '</section>'));
    }
}
