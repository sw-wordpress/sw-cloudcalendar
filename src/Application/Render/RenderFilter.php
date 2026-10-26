<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Domain\View\DisplayMode;

/**
 * Normalized, typed filter of a shortcode, block or REST request (SPECIFICATIONS.md §10.2). Null means "not given";
 * the view defaults apply then. Filters only shape the output, they never grant access.
 */
final class RenderFilter
{
    /**
     * @param list<int>|null $sourceIds
     * @param list<int>      $exclude
     */
    public function __construct(
        public readonly ?array $sourceIds = null,
        public readonly ?DisplayMode $mode = null,
        public readonly ?int $limit = null,
        public readonly ?int $days = null,
        public readonly ?string $startDate = null,
        public readonly ?string $endDate = null,
        public readonly ?bool $past = null,
        public readonly ?string $search = null,
        public readonly ?string $location = null,
        public readonly array $exclude = [],
        public readonly ?string $sort = null,
        public readonly ?int $templateId = null,
        public readonly ?bool $showImages = null,
        public readonly ?bool $showAttachments = null,
        public readonly ?string $month = null,
        public readonly int $page = 1,
        public readonly ?int $perPage = null,
        public readonly ?bool $navigation = null,
    ) {
    }

    /**
     * Request values override defaults field by field; source ids are intersected, never extended.
     */
    public function withDefaults(self $defaults): self
    {
        $sources = $this->sourceIds;
        if ($defaults->sourceIds !== null) {
            $sources = $sources === null ? $defaults->sourceIds : array_values(array_intersect($sources, $defaults->sourceIds));
        }
        $hasExplicitRange = $this->startDate !== null || $this->endDate !== null;
        return new self(
            $sources,
            $this->mode ?? $defaults->mode,
            $this->limit ?? $defaults->limit,
            // start/end > days > view default: an explicit range in the request suppresses the default `days`.
            $this->days ?? ($hasExplicitRange ? null : $defaults->days),
            $this->startDate ?? ($this->days !== null ? null : $defaults->startDate),
            $this->endDate ?? ($this->days !== null ? null : $defaults->endDate),
            $this->past ?? $defaults->past,
            $this->search ?? $defaults->search,
            $this->location ?? $defaults->location,
            array_values(array_unique(array_merge($defaults->exclude, $this->exclude))),
            $this->sort ?? $defaults->sort,
            $this->templateId ?? $defaults->templateId,
            $this->showImages ?? $defaults->showImages,
            $this->showAttachments ?? $defaults->showAttachments,
            $this->month ?? $defaults->month,
            $this->page,
            $this->perPage ?? $defaults->perPage,
            $this->navigation ?? $defaults->navigation,
        );
    }

    /**
     * Canonical representation; part of render cache keys.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $sources = $this->sourceIds;
        if ($sources !== null) {
            sort($sources);
        }
        $exclude = $this->exclude;
        sort($exclude);
        return [
            'source' => $sources,
            'view' => $this->mode?->value,
            'limit' => $this->limit,
            'days' => $this->days,
            'start' => $this->startDate,
            'end' => $this->endDate,
            'past' => $this->past,
            'search' => $this->search,
            'location' => $this->location,
            'exclude' => $exclude,
            'sort' => $this->sort,
            'template' => $this->templateId,
            'show_images' => $this->showImages,
            'show_attachments' => $this->showAttachments,
            'month' => $this->month,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'navigation' => $this->navigation,
        ];
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray()));
    }
}
