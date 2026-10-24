<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Application\Port\DateFormatterInterface;
use SW\Calendar\Application\Port\HtmlSanitizerInterface;
use SW\Calendar\Application\Port\UrlGeneratorInterface;
use SW\Calendar\Application\Render\Component\AttachmentRenderer;
use SW\Calendar\Application\Render\Component\MonthGrid;
use SW\Calendar\Application\Render\Template\CompiledTemplate;
use SW\Calendar\Application\Render\Template\TemplateCompiler;
use SW\Calendar\Application\Render\Template\TemplateRenderer;
use SW\Calendar\Application\Render\Template\TrustedHtml;
use SW\Calendar\Domain\View\DisplayMode;

/**
 * Renders templates against the fixed preview datasets with the same engine and components as the frontend.
 */
final class PreviewRenderer
{
    public function __construct(
        private readonly DateFormatterInterface $dates,
        private readonly UrlGeneratorInterface $urls,
        private readonly HtmlSanitizerInterface $sanitizer,
        private readonly RenderLabels $labels,
        private readonly int $weekStart = 1,
        private readonly TemplateRenderer $renderer = new TemplateRenderer(),
    ) {
    }

    /**
     * @param list<string>          $scopes Scope classes of the three templates.
     * @param array<string, string> $css    Scope class => scoped CSS (returned unchanged for the caller).
     * @return array{html: string, css: array<string, string>}
     */
    public function render(CompiledTemplate $container, CompiledTemplate $event, ?CompiledTemplate $detail, DisplayMode $mode, \DateTimeImmutable $now, \DateTimeZone $tz, array $scopes, array $css): array
    {
        $data = PreviewData::build($now, $tz);
        $values = new EventValues($this->dates, $this->urls, $this->sanitizer, new AttachmentRenderer($this->urls, $this->labels));
        $calendar = [
            'calendar.title' => 'Vorschau',
            'calendar.timezone' => $tz->getName(),
            'calendar.range_label' => $this->dates->format($now, $tz, 'F Y'),
            'calendar.count' => (string) count($data['items']),
            'calendar.has_events' => true,
        ];
        $rendered = [];
        $detailHtml = '';
        foreach ($data['items'] as $item) {
            $occurrence = $item['occurrence'];
            $map = $values->build($occurrence, $data['sources'][$occurrence->sourceId], $item['attachments'], 0, $tz, $this->dates->defaultDateFormat(), $this->dates->defaultTimeFormat(), true, true);
            $rendered[] = ['occurrence' => $occurrence, 'html' => $this->renderer->render($event, $calendar + $map)];
            if ($detail !== null && $detailHtml === '') {
                $detailHtml = $this->renderer->render($detail, $calendar + $map);
            }
        }
        if ($mode === DisplayMode::Month) {
            $range = (new RangeResolver())->resolve(new RenderFilter(), DisplayMode::Month, $tz, $now, $this->weekStart);
            $grid = new MonthGrid($this->dates, $this->urls, $this->labels);
            $events = $grid->render($range, $rendered, $tz, $now);
            $navigation = $grid->navigation(0, $range->month ?? $now, $tz);
        } else {
            $tag = $mode === DisplayMode::Cards ? 'ul' : 'ol';
            $list = '<' . $tag . ' class="swc-events swc-events--' . $mode->value . '">';
            foreach ($rendered as $item) {
                $list .= '<li class="swc-events__item">' . $item['html'] . '</li>';
            }
            $events = new TrustedHtml($list . '</' . $tag . '>');
            $navigation = new TrustedHtml('');
        }
        $html = '<div class="swc-calendar swc-preview swc-mode-' . $mode->value . ' ' . TemplateCompiler::escape(implode(' ', $scopes)) . '">'
            . $this->renderer->render($container, $calendar + ['calendar.events' => $events, 'calendar.navigation' => $navigation])
            . '</div>';
        if ($detailHtml !== '') {
            $html .= '<hr class="swc-preview__separator"><div class="swc-detail swc-preview ' . TemplateCompiler::escape(implode(' ', $scopes)) . '">' . $detailHtml . '</div>';
        }
        return ['html' => $html, 'css' => $css];
    }
}
