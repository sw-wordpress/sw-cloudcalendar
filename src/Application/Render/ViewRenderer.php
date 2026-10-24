<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Application\Access\SourceAccessInterface;
use SW\Calendar\Application\Port\AttachmentQueryInterface;
use SW\Calendar\Application\Port\CacheInterface;
use SW\Calendar\Application\Port\DateFormatterInterface;
use SW\Calendar\Application\Port\OccurrenceQueryInterface;
use SW\Calendar\Application\Port\SourceRepositoryInterface;
use SW\Calendar\Application\Port\UrlGeneratorInterface;
use SW\Calendar\Application\Port\ViewRepositoryInterface;
use SW\Calendar\Application\Render\Component\MonthGrid;
use SW\Calendar\Application\Render\Template\TemplateCompiler;
use SW\Calendar\Application\Render\Template\TemplateRenderer;
use SW\Calendar\Application\Render\Template\TrustedHtml;
use SW\Calendar\Domain\Attachment\Attachment;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Domain\Template\TemplateKind;
use SW\Calendar\Domain\View\CalendarView;
use SW\Calendar\Domain\View\DisplayMode;
use SW\Calendar\Support\ClockInterface;

/**
 * The single read path for shortcode, block and REST (SPECIFICATIONS.md §10.3): authorize sources, resolve filter
 * and window, query local data only, render templates, optionally use the HTML cache for public scopes.
 *
 * `effective_source_ids = view_source_ids ∩ requested_source_ids ∩ authorized_source_ids` is computed BEFORE any
 * cache lookup; private scopes are never cached.
 */
final class ViewRenderer
{
    private const MIN_TTL = 30;
    private const MAX_TTL = 900;

    public function __construct(
        private readonly ViewRepositoryInterface $views,
        private readonly SourceRepositoryInterface $sources,
        private readonly SourceAccessInterface $access,
        private readonly OccurrenceQueryInterface $occurrences,
        private readonly AttachmentQueryInterface $attachments,
        private readonly TemplateProvider $templates,
        private readonly EventValues $values,
        private readonly DateFormatterInterface $dates,
        private readonly UrlGeneratorInterface $urls,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly RenderSettings $settings,
        private readonly FilterSchema $schema = new FilterSchema(),
        private readonly RangeResolver $ranges = new RangeResolver(),
        private readonly TemplateRenderer $renderer = new TemplateRenderer(),
    ) {
    }

    /**
     * Whether the view contains sources that are not public. Such views are rendered through the non-cacheable REST
     * endpoint instead of being serialized into (potentially publicly cached) page HTML (SPECIFICATIONS.md §9.3).
     */
    public function requiresDynamicLoading(int $viewId): bool
    {
        $view = $this->views->find($viewId);
        if ($view === null || !$view->enabled) {
            return false;
        }
        foreach ($this->sources->findMany($view->sourceIds) as $source) {
            if ($source->isReadable() && $source->visibility !== Visibility::Public) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $rawFilter Shortcode/block/REST parameters.
     * @param bool                 $strict    Reject invalid parameters (REST) instead of ignoring them.
     */
    public function render(int $viewId, array $rawFilter, ?int $userId, bool $strict = false): RenderResult
    {
        $view = $this->views->find($viewId);
        if ($view === null || !$view->enabled) {
            return RenderResult::notFound();
        }
        $parsed = $this->schema->normalize($rawFilter);
        if ($strict && !$parsed->isValid()) {
            return new RenderResult(RenderResult::INVALID, errors: $parsed->errors);
        }
        $filter = $parsed->filter->withDefaults($this->schema->normalize($view->defaultFilters)->filter);
        [$allowed, $sources] = $this->effectiveSources($view, $filter, $userId);

        $mode = $filter->mode !== null && $view->allowsMode($filter->mode) ? $filter->mode : $view->displayMode;
        $preset = PresetLibrary::defaultFor($mode);
        $container = $this->templates->resolve($view->containerTemplateId, TemplateKind::Container, $preset);
        $eventTemplateId = $filter->templateId !== null && $view->allowsTemplate($filter->templateId) ? $filter->templateId : $view->eventTemplateId;
        $event = $this->templates->resolve($eventTemplateId, TemplateKind::Event, $preset);
        $tz = $this->timeZone($view);
        $now = $this->clock->now();
        $range = $this->ranges->resolve($filter, $mode, $tz, $now, $this->settings->weekStart);

        // "Public" is a property of the whole view: if it contains any restricted source the output depends on the
        // user and must never be shared through HTTP caches, even if this user only sees the public part.
        $public = $allowed !== [] && $this->allPublic($sources) && !$this->requiresDynamicLoading($view->id);
        $cacheable = $allowed !== [] && $this->allPublic($sources) && $view->htmlCache && $this->settings->htmlCacheEnabled;
        $cacheKey = $cacheable ? $this->cacheKey($view, $sources, $filter, $mode, [$container, $event], $tz) : '';
        if ($cacheable) {
            $hit = $this->cache->get($cacheKey);
            $cached = $hit === null ? null : RenderResult::fromCache($hit);
            if ($cached !== null) {
                return $cached;
            }
        }

        $limit = $mode === DisplayMode::Month ? 1000 : min($filter->limit ?? $filter->perPage ?? $this->settings->defaultLimit, FilterSchema::MAX_LIMIT);
        $offset = $mode === DisplayMode::Month ? 0 : ($filter->page - 1) * $limit;
        $found = $allowed === [] ? ['items' => [], 'total' => 0] : $this->occurrences->find(
            $allowed,
            $range,
            $filter->search,
            $filter->location,
            $filter->exclude,
            $filter->sort === 'desc',
            $limit,
            $offset,
            $tz->getOffset($now),
        );
        $items = $found['items'];
        $showImages = $filter->showImages ?? true;
        $showFiles = $filter->showAttachments ?? true;
        $attachments = $this->attachmentsFor($items, $allowed, $showImages || $showFiles);

        $calendar = [
            'calendar.title' => $view->name,
            'calendar.timezone' => $tz->getName(),
            'calendar.range_label' => $this->rangeLabel($range, $mode, $tz, $view),
            'calendar.count' => (string) count($items),
            'calendar.has_events' => $items !== [],
        ];
        $rendered = [];
        foreach ($items as $occurrence) {
            $values = $this->values->build(
                $occurrence,
                $sources[$occurrence->sourceId] ?? null,
                $attachments[$occurrence->id] ?? [],
                $view->id,
                $tz,
                $this->dateFormat($view),
                $this->timeFormat($view),
                $showImages,
                $showFiles,
                $view->colorRules[$occurrence->sourceId] ?? null,
            );
            $rendered[] = ['occurrence' => $occurrence, 'html' => $this->renderer->render($event->compiled, $calendar + $values)];
        }

        $grid = new MonthGrid($this->dates, $this->urls, $this->settings->labels);
        if ($mode === DisplayMode::Month) {
            $eventsHtml = $grid->render($range, $rendered, $tz, $now);
            $navigation = $grid->navigation($view->id, $range->month ?? $now, $tz);
        } else {
            $eventsHtml = $this->listHtml($rendered, $mode);
            $navigation = new TrustedHtml('');
        }
        $html = $this->renderer->render($container->compiled, $calendar + [
            'calendar.events' => $eventsHtml,
            'calendar.navigation' => $navigation,
        ]);
        $wrapped = $this->wrap($view, $mode, [$container, $event], $html, $filter, $range);
        $result = new RenderResult(
            RenderResult::OK,
            $wrapped,
            $this->collectCss([$container, $event]),
            false,
            $public,
            $parsed->errors,
            $found['total'],
        );
        if ($cacheable) {
            $this->cache->set($cacheKey, $result->toJson(), $this->ttl($now, $range, $items, $tz));
        }
        return $result;
    }

    /**
     * Detail view of one occurrence; only if it belongs to an authorized source of the (enabled) view.
     */
    public function renderDetail(int $viewId, int $occurrenceId, ?int $userId): RenderResult
    {
        $view = $this->views->find($viewId);
        $occurrence = $this->occurrences->findById($occurrenceId);
        if ($view === null || !$view->enabled || $occurrence === null || !$this->isVisible($occurrence)) {
            return RenderResult::notFound();
        }
        if (!in_array($occurrence->sourceId, $view->sourceIds, true) || !$this->access->canReadSource($occurrence->sourceId, $userId)) {
            return RenderResult::notFound();
        }
        $source = $this->sources->find($occurrence->sourceId);
        $preset = PresetLibrary::defaultFor($view->displayMode);
        $detail = $this->templates->resolve($view->detailTemplateId, TemplateKind::Detail, $preset);
        $tz = $this->timeZone($view);
        $attachments = $this->attachmentsFor([$occurrence], [$occurrence->sourceId], true);
        $values = $this->values->build(
            $occurrence,
            $source,
            $attachments[$occurrence->id] ?? [],
            $view->id,
            $tz,
            $this->dateFormat($view),
            $this->timeFormat($view),
            true,
            true,
            $view->colorRules[$occurrence->sourceId] ?? null,
        );
        $html = $this->renderer->render($detail->compiled, [
            'calendar.title' => $view->name,
            'calendar.timezone' => $tz->getName(),
            'calendar.range_label' => '',
            'calendar.count' => '1',
            'calendar.has_events' => true,
        ] + $values);
        $wrapped = '<div class="swc-detail swc-view-' . $view->id . ' ' . $detail->scopeClass . '" data-swc-view="' . $view->id
            . '" data-swc-event="' . $occurrence->id . '">' . $html . '</div>';
        return new RenderResult(
            RenderResult::OK,
            $wrapped,
            $this->collectCss([$detail]),
            false,
            $source !== null && $source->visibility === Visibility::Public,
        );
    }

    /**
     * Normalized event data for `GET /views/{id}/events` (only fields intended for publication).
     *
     * @param array<string, mixed> $rawFilter
     * @return array{status: string, errors?: array<string, string>, events?: list<array<string, mixed>>, pagination?: array<string, int>, meta?: array<string, mixed>, public?: bool}
     */
    public function events(int $viewId, array $rawFilter, ?int $userId): array
    {
        $view = $this->views->find($viewId);
        if ($view === null || !$view->enabled) {
            return ['status' => RenderResult::NOT_FOUND];
        }
        $parsed = $this->schema->normalize($rawFilter);
        if (!$parsed->isValid()) {
            return ['status' => RenderResult::INVALID, 'errors' => $parsed->errors];
        }
        $filter = $parsed->filter->withDefaults($this->schema->normalize($view->defaultFilters)->filter);
        [$allowed, $sources] = $this->effectiveSources($view, $filter, $userId);
        $mode = $filter->mode !== null && $view->allowsMode($filter->mode) ? $filter->mode : DisplayMode::List;
        $tz = $this->timeZone($view);
        $now = $this->clock->now();
        $range = $this->ranges->resolve($filter, $mode, $tz, $now, $this->settings->weekStart);
        $perPage = min($filter->perPage ?? $filter->limit ?? 20, FilterSchema::MAX_LIMIT);
        $found = $allowed === [] ? ['items' => [], 'total' => 0] : $this->occurrences->find(
            $allowed,
            $range,
            $filter->search,
            $filter->location,
            $filter->exclude,
            $filter->sort === 'desc',
            $perPage,
            ($filter->page - 1) * $perPage,
            $tz->getOffset($now),
        );
        $attachments = $this->attachmentsFor($found['items'], $allowed, ($filter->showAttachments ?? true) || ($filter->showImages ?? true));
        $events = [];
        $lastSync = null;
        foreach ($found['items'] as $occurrence) {
            [$start, $end, $endInclusive] = EventValues::localTimes($occurrence, $tz);
            $events[] = [
                'id' => $occurrence->id,
                'source_id' => $occurrence->sourceId,
                'title' => $occurrence->title,
                'start' => $occurrence->allDay ? $start->format('Y-m-d') : $start->format(DATE_ATOM),
                'end' => $occurrence->allDay ? $endInclusive->format('Y-m-d') : $end->format(DATE_ATOM),
                'all_day' => $occurrence->allDay,
                'status' => $occurrence->status->value,
                'location' => $occurrence->location,
                'description' => $occurrence->descriptionText,
                'color' => $view->colorRules[$occurrence->sourceId] ?? ($sources[$occurrence->sourceId]->color ?? null),
                'url' => $this->urls->eventDetail($view->id, $occurrence->id),
                'external_url' => $occurrence->externalUrl !== '' ? $occurrence->externalUrl : null,
                'attachments' => array_map(fn (Attachment $a): array => [
                    'id' => $a->id,
                    'name' => $a->filename,
                    'type' => $a->mime,
                    'size' => $a->sizeBytes,
                    'is_image' => $a->isImage,
                    'url' => $this->urls->attachmentContent($a->id),
                    'preview_url' => $a->previewKey !== null ? $this->urls->attachmentPreview($a->id) : null,
                ], $this->visibleAttachments($attachments[$occurrence->id] ?? [], $filter)),
            ];
        }
        foreach ($sources as $source) {
            if ($source->lastSuccessAt !== null && ($lastSync === null || $source->lastSuccessAt < $lastSync)) {
                $lastSync = $source->lastSuccessAt;
            }
        }
        return [
            'status' => RenderResult::OK,
            'events' => $events,
            'pagination' => ['page' => $filter->page, 'per_page' => $perPage, 'total' => $found['total']],
            'meta' => [
                'timezone' => $tz->getName(),
                'last_synced_at' => $lastSync?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ],
            'public' => $allowed !== [] && $this->allPublic($sources) && !$this->requiresDynamicLoading($view->id),
        ];
    }

    /**
     * @return array{0: list<int>, 1: array<int, Source>}
     */
    private function effectiveSources(CalendarView $view, RenderFilter $filter, ?int $userId): array
    {
        $candidates = $view->sourceIds;
        if ($filter->sourceIds !== null) {
            $candidates = array_values(array_intersect($view->sourceIds, $filter->sourceIds));
        }
        $allowed = array_values($this->access->filterAllowedSources($candidates, $userId));
        return [$allowed, $this->sources->findMany($allowed)];
    }

    /**
     * @param array<int, Source> $sources
     */
    private function allPublic(array $sources): bool
    {
        foreach ($sources as $source) {
            if ($source->visibility !== Visibility::Public) {
                return false;
            }
        }
        return true;
    }

    private function isVisible(Occurrence $occurrence): bool
    {
        return in_array($occurrence->status->value, OccurrenceStatus::visibleValues(), true);
    }

    /**
     * @param list<Occurrence> $items
     * @param list<int>        $allowedSourceIds
     * @return array<int, list<Attachment>>
     */
    private function attachmentsFor(array $items, array $allowedSourceIds, bool $needed): array
    {
        if (!$needed || $items === []) {
            return [];
        }
        $byOccurrence = $this->attachments->deliverableFor(array_map(static fn (Occurrence $o): int => $o->id, $items));
        // Defense in depth: attachments always inherit the ACL of their own source.
        foreach ($byOccurrence as $occurrenceId => $list) {
            $byOccurrence[$occurrenceId] = array_values(array_filter(
                $list,
                static fn (Attachment $a): bool => $a->isDeliverable() && in_array($a->sourceId, $allowedSourceIds, true)
            ));
        }
        return $byOccurrence;
    }

    /**
     * @param list<Attachment> $attachments
     * @return list<Attachment>
     */
    private function visibleAttachments(array $attachments, RenderFilter $filter): array
    {
        return array_values(array_filter(
            $attachments,
            static fn (Attachment $a): bool => $a->isImage ? ($filter->showImages ?? true) : ($filter->showAttachments ?? true)
        ));
    }

    /**
     * @param list<array{occurrence: Occurrence, html: string}> $rendered
     */
    private function listHtml(array $rendered, DisplayMode $mode): TrustedHtml
    {
        if ($rendered === []) {
            return new TrustedHtml('<p class="swc-empty">' . TemplateCompiler::escape($this->settings->labels->noEvents) . '</p>');
        }
        $tag = $mode === DisplayMode::Cards ? 'ul' : 'ol';
        $html = '<' . $tag . ' class="swc-events swc-events--' . $mode->value . '">';
        foreach ($rendered as $item) {
            $occurrence = $item['occurrence'];
            $html .= '<li class="swc-events__item' . ($occurrence->status === OccurrenceStatus::Tentative ? ' is-tentative' : '') . '"'
                . ' data-swc-event="' . $occurrence->id . '" data-swc-source="' . $occurrence->sourceId . '">' . $item['html'] . '</li>';
        }
        return new TrustedHtml($html . '</' . $tag . '>');
    }

    /**
     * @param list<ResolvedTemplate> $templates
     */
    private function wrap(CalendarView $view, DisplayMode $mode, array $templates, string $html, RenderFilter $filter, QueryRange $range): string
    {
        $classes = ['swc-calendar', 'swc-view-' . $view->id, 'swc-mode-' . $mode->value];
        foreach ($templates as $template) {
            $classes[] = $template->scopeClass;
        }
        $state = $filter->toArray();
        if ($range->month !== null) {
            $state['month'] = $range->month->format('Y-m');
        }
        $state = array_filter($state, static fn ($value): bool => $value !== null && $value !== []);
        return '<div class="' . implode(' ', array_unique($classes)) . '" data-swc-view="' . $view->id . '" data-swc-mode="' . $mode->value . '"'
            . ' data-swc-state="' . TemplateCompiler::escape((string) json_encode($state)) . '">' . $html . '</div>';
    }

    /**
     * @param list<ResolvedTemplate> $templates
     * @return array<string, string>
     */
    private function collectCss(array $templates): array
    {
        $css = [];
        foreach ($templates as $template) {
            if ($template->scopedCss !== '') {
                $css[$template->scopeClass] = $template->scopedCss;
            }
        }
        return $css;
    }

    /**
     * @param array<int, Source>     $sources
     * @param list<ResolvedTemplate> $templates
     */
    private function cacheKey(CalendarView $view, array $sources, RenderFilter $filter, DisplayMode $mode, array $templates, \DateTimeZone $tz): string
    {
        $revisions = [];
        foreach ($sources as $source) {
            $revisions[$source->id] = $source->revision;
        }
        ksort($revisions);
        $parts = [
            'view' => $view->id,
            'view_revision' => $view->revision,
            'sources' => $revisions,
            'filter' => $filter->hash(),
            'mode' => $mode->value,
            'templates' => array_map(static fn (ResolvedTemplate $t): string => $t->cacheToken(), $templates),
            'locale' => $this->settings->locale,
            'tz' => $tz->getName(),
            'week_start' => $this->settings->weekStart,
            'scope' => 'public',
            'engine' => TemplateCompiler::ENGINE_VERSION,
        ];
        return 'swc:html:' . hash('sha256', (string) json_encode($parts));
    }

    /**
     * Cached HTML expires at the next relevant boundary: the next local midnight (day based windows, "today" marker)
     * and, for windows starting now, the end of the first displayed event (it must disappear when it is over).
     *
     * @param list<Occurrence> $items
     */
    private function ttl(\DateTimeImmutable $now, QueryRange $range, array $items, \DateTimeZone $tz): int
    {
        $boundary = $now->setTimezone($tz)->setTime(0, 0)->modify('+1 day')->getTimestamp();
        if ($range->relativeToNow) {
            foreach ($items as $occurrence) {
                $end = $occurrence->allDay ? EventValues::localTimes($occurrence, $tz)[1]->getTimestamp() : $occurrence->endUtc->getTimestamp();
                if ($end > $now->getTimestamp()) {
                    $boundary = min($boundary, $end);
                }
            }
        }
        return max(self::MIN_TTL, min(self::MAX_TTL, $boundary - $now->getTimestamp()));
    }

    private function rangeLabel(QueryRange $range, DisplayMode $mode, \DateTimeZone $tz, CalendarView $view): string
    {
        if ($mode === DisplayMode::Month && $range->month !== null) {
            return $this->dates->format($range->month, $tz, 'F Y');
        }
        $start = new \DateTimeImmutable($range->floatingStart->format('Y-m-d H:i:s'), $tz);
        $end = (new \DateTimeImmutable($range->floatingEnd->format('Y-m-d H:i:s'), $tz))->modify('-1 second');
        return $this->dates->format($start, $tz, $this->dateFormat($view)) . ' – ' . $this->dates->format($end, $tz, $this->dateFormat($view));
    }

    private function timeZone(CalendarView $view): \DateTimeZone
    {
        if ($view->timezone !== '') {
            return $view->timeZone();
        }
        try {
            return new \DateTimeZone($this->settings->defaultTimezone);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    private function dateFormat(CalendarView $view): string
    {
        return $view->dateFormat !== '' ? $view->dateFormat : $this->dates->defaultDateFormat();
    }

    private function timeFormat(CalendarView $view): string
    {
        return $view->timeFormat !== '' ? $view->timeFormat : $this->dates->defaultTimeFormat();
    }
}
