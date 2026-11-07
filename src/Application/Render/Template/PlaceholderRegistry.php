<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

use SW\Calendar\Domain\Template\TemplateKind;

/**
 * The complete V1 placeholder vocabulary (SPECIFICATIONS.md §11.1). Only registered names are valid.
 */
final class PlaceholderRegistry
{
    private const CALENDAR = [
        'calendar.title' => [PlaceholderType::Text, 'Name of the calendar view'],
        'calendar.timezone' => [PlaceholderType::Text, 'Time zone used for display'],
        'calendar.range_label' => [PlaceholderType::Text, 'Displayed period, e.g. "November 2026"'],
        'calendar.count' => [PlaceholderType::Text, 'Number of displayed events'],
        'calendar.has_events' => [PlaceholderType::Flag, 'At least one event is displayed'],
    ];

    private const CONTAINER_ONLY = [
        'calendar.events_by_month' => [PlaceholderType::Html, 'Rendered events grouped by start month, with localized month and year headings'],
        'calendar.events' => [PlaceholderType::Html, 'Rendered events (list, cards or month grid)'],
        'calendar.navigation' => [PlaceholderType::Html, 'Previous/next navigation of the month view'],
    ];

    private const EVENT = [
        'event.labels.images' => [PlaceholderType::Text, 'Localized images label'],
        'event.has_multiple_images' => [PlaceholderType::Flag, 'Event has more than one displayable image'],
        'event.labels.details' => [PlaceholderType::Text, 'Localized event details heading'],
        'event.labels.date' => [PlaceholderType::Text, 'Localized date label'],
        'event.labels.time' => [PlaceholderType::Text, 'Localized time label'],
        'event.labels.location' => [PlaceholderType::Text, 'Localized location label'],
        'event.labels.all_day' => [PlaceholderType::Text, 'Localized all-day label'],
        'event.labels.show_image' => [PlaceholderType::Text, 'Localized view image label'],
        'event.color_text' => [PlaceholderType::Color, 'Contrasting text color for the source color'],
        'event.id' => [PlaceholderType::Text, 'Internal occurrence id'],
        'event.title' => [PlaceholderType::Text, 'Title'],
        'event.description' => [PlaceholderType::Text, 'Description as plain text'],
        'event.description_html' => [PlaceholderType::Html, 'Description as sanitized HTML'],
        'event.excerpt' => [PlaceholderType::Text, 'Shortened plain text description'],
        'event.location' => [PlaceholderType::Text, 'Location'],
        'event.url' => [PlaceholderType::Url, 'Link to the event at the provider (if available)'],
        'event.detail_url' => [PlaceholderType::Url, 'Link to the event detail view'],
        'event.start_date' => [PlaceholderType::Text, 'Start date'],
        'event.start_time' => [PlaceholderType::Text, 'Start time (empty for all-day events)'],
        'event.end_date' => [PlaceholderType::Text, 'End date (inclusive for all-day events)'],
        'event.end_time' => [PlaceholderType::Text, 'End time (empty for all-day events)'],
        'event.date_range' => [PlaceholderType::Text, 'Complete formatted date and time range'],
        'event.start_iso' => [PlaceholderType::Text, 'Start as ISO 8601 (for <time datetime>)'],
        'event.end_iso' => [PlaceholderType::Text, 'End as ISO 8601'],
        'event.day' => [PlaceholderType::Text, 'Day of month of the start'],
        'event.month_short' => [PlaceholderType::Text, 'Abbreviated month name of the start'],
        'event.year_short' => [PlaceholderType::Text, 'Two-digit year of the start'],
        'event.weekday_short' => [PlaceholderType::Text, 'Abbreviated weekday name of the start'],
        'event.weekday' => [PlaceholderType::Text, 'Weekday name of the start'],
        'event.all_day' => [PlaceholderType::Flag, 'All-day event'],
        'event.multi_day' => [PlaceholderType::Flag, 'Event spans several days'],
        'event.tentative' => [PlaceholderType::Flag, 'Event is tentative'],
        'event.calendar_name' => [PlaceholderType::Text, 'Name of the source calendar'],
        'event.color' => [PlaceholderType::Color, 'Colour of the source calendar (#rrggbb)'],
        'event.image_alt' => [PlaceholderType::Text, 'Alternative text of the preview image'],
        'event.image' => [PlaceholderType::Url, 'Local preview URL of the first image attachment'],
        'event.has_images' => [PlaceholderType::Flag, 'Event has displayable images'],
        'event.has_files' => [PlaceholderType::Flag, 'Event has downloadable files'],
        'event.attachments.gallery' => [PlaceholderType::Html, 'Image gallery (local URLs only)'],
        'event.attachments.file_list' => [PlaceholderType::Html, 'Download list with icon, name and size'],
    ];

    public function find(string $name, TemplateKind $kind): ?PlaceholderType
    {
        return $this->all($kind)[$name][0] ?? null;
    }

    /**
     * @return array<string, array{0: PlaceholderType, 1: string}>
     */
    public function all(TemplateKind $kind): array
    {
        return match ($kind) {
            TemplateKind::Container => self::CALENDAR + self::CONTAINER_ONLY,
            TemplateKind::Event, TemplateKind::Detail => self::CALENDAR + self::EVENT,
        };
    }

    /**
     * Reference for the editor's variable help and autocomplete.
     *
     * @return list<array{name: string, type: string, description: string}>
     */
    public function describe(TemplateKind $kind): array
    {
        $result = [];
        foreach ($this->all($kind) as $name => [$type, $description]) {
            $result[] = ['name' => $name, 'type' => $type->value, 'description' => $description];
        }
        return $result;
    }
}
