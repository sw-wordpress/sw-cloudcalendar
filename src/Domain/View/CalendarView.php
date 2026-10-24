<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\View;

/**
 * A saved calendar view (SPECIFICATIONS.md §3.4). `sourceIds` is the MAXIMUM set of sources the view may show;
 * shortcode/block parameters can only narrow it.
 */
final class CalendarView
{
    /**
     * @param list<int>              $sourceIds
     * @param array<string, mixed>   $defaultFilters Raw filter defaults, normalized by the filter schema.
     * @param list<DisplayMode>      $allowedModes
     * @param list<int>              $allowedTemplateIds Event templates selectable through `template`.
     * @param array<int, string>     $colorRules Source id => #rrggbb override.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $enabled,
        public readonly array $sourceIds,
        public readonly array $defaultFilters,
        public readonly DisplayMode $displayMode,
        public readonly array $allowedModes,
        public readonly ?int $containerTemplateId,
        public readonly ?int $eventTemplateId,
        public readonly ?int $detailTemplateId,
        public readonly array $allowedTemplateIds,
        public readonly string $timezone,
        public readonly string $language,
        public readonly array $colorRules,
        public readonly int $revision,
        public readonly bool $htmlCache = true,
        public readonly string $dateFormat = '',
        public readonly string $timeFormat = '',
    ) {
    }

    public function allowsMode(DisplayMode $mode): bool
    {
        return $mode === $this->displayMode || in_array($mode, $this->allowedModes, true);
    }

    public function allowsTemplate(int $templateId): bool
    {
        return $templateId === $this->eventTemplateId || in_array($templateId, $this->allowedTemplateIds, true);
    }

    public function timeZone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->timezone !== '' ? $this->timezone : 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
