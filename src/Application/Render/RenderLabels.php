<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

/**
 * Translated UI strings of plugin generated markup. Built by the WordPress layer with gettext literals so the
 * framework independent renderer needs no translation API.
 */
final class RenderLabels
{
    public function __construct(
        public readonly string $noEvents = 'No events.',
        public readonly string $previousMonth = 'Previous month',
        public readonly string $nextMonth = 'Next month',
        public readonly string $calendarNavigation = 'Calendar navigation',
        public readonly string $images = 'Images',
        public readonly string $files = 'Files',
        public readonly string $download = 'Download %s',
        public readonly string $allDay = 'All day',
        public readonly string $today = 'Today',
        public readonly string $moreEvents = '%d more',
        public readonly string $eventsOnDay = '%1$s, %2$d events',
        public readonly string $loading = 'Loading calendar…',
        public readonly string $requiresJavascript = 'Please enable JavaScript to see this calendar.',
        public readonly string $decimalPoint = '.',
        public readonly string $thousandsSeparator = ',',
        public readonly string $previewTitle = 'Preview',
    ) {
    }
}
