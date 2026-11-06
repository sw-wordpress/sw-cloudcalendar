<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Component;

use SW\Calendar\Application\Port\UrlGeneratorInterface;
use SW\Calendar\Application\Render\FilterSchema;
use SW\Calendar\Application\Render\RenderFilter;
use SW\Calendar\Application\Render\RenderLabels;
use SW\Calendar\Application\Render\Template\TemplateCompiler;
use SW\Calendar\Domain\View\CalendarView;
use SW\Calendar\Domain\View\DisplayMode;

/** Links styled as tabs and a GET search form also work without JavaScript. */
final class CalendarControls
{
    public function __construct(private readonly UrlGeneratorInterface $urls, private readonly RenderLabels $labels)
    {
    }

    public function render(CalendarView $view, DisplayMode $mode, RenderFilter $filter, int $total, ?string $month = null): string
    {
        $state = ['view' => $mode->value, 'search' => $filter->search, 'month' => $month ?? $filter->month, 'page' => 1];
        $html = '';
        $modes = array_values(array_filter([DisplayMode::List, DisplayMode::Cards, DisplayMode::Month], $view->allowsMode(...)));
        if ($filter->modeSwitcher === true && count($modes) > 1) {
            $html .= '<nav class="swc-mode-tabs" aria-label="' . $this->e($this->labels->displayModes) . '">';
            foreach ($modes as $candidate) {
                $label = match ($candidate) {
                    DisplayMode::List => $this->labels->listMode,
                    DisplayMode::Cards => $this->labels->cardsMode,
                    DisplayMode::Month => $this->labels->monthMode,
                };
                $url = $this->urls->calendar($view->id, array_replace($state, ['view' => $candidate->value]));
                $html .= '<a class="swc-mode-tabs__tab" data-swc-switch="' . $candidate->value . '" href="' . $this->e($url) . '"'
                    . ($candidate === $mode ? ' aria-current="page"' : '') . '>' . $this->e($label) . '</a>';
            }
            $html .= '</nav>';
        }
        if ($filter->showSearch ?? true) {
            $url = $this->urls->calendar($view->id, $state);
            $action = (string) preg_replace('/[?#].*$/', '', $url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
            $html .= '<form class="swc-search" method="get" action="' . $this->e($action) . '" role="search">';
            foreach ($parameters as $name => $value) {
                if ($name !== 'swc_search' && is_scalar($value)) {
                    $html .= '<input type="hidden" name="' . $this->e((string) $name) . '" value="' . $this->e((string) $value) . '">';
                }
            }
            $html .= '<label class="swc-search__label"><span class="swc-screen-reader-text">' . $this->e($this->labels->searchEvents) . '</span>'
                . '<input type="search" name="swc_search" value="' . $this->e($filter->search ?? '') . '" maxlength="' . FilterSchema::MAX_TEXT . '" placeholder="' . $this->e($this->labels->searchEvents) . '" aria-description="' . $this->e($this->labels->searchHint) . '"></label>'
                . '<button type="submit"><span class="swc-search__icon" aria-hidden="true"></span>' . $this->e($this->labels->searchSubmit) . '</button>'
                . '<p class="swc-search__hint swc-screen-reader-text">' . $this->e($this->labels->searchHint) . '</p>';
            if ($filter->search !== null && $filter->search !== '') {
                $html .= '<p class="swc-search__results" role="status">' . $this->e(sprintf($this->labels->searchResults, $total)) . '</p>';
            }
            $html .= '</form>';
        }
        return $html === '' ? '' : '<div class="swc-controls">' . $html . '</div>';
    }

    private function e(string $value): string
    {
        return TemplateCompiler::escape($value);
    }
}
