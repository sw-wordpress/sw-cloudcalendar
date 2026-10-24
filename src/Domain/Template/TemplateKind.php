<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Template;

enum TemplateKind: string
{
    /** Wraps the rendered event list (`{{calendar.events}}`). */
    case Container = 'container';
    /** One event in a list, card grid or month cell. */
    case Event = 'event';
    /** Event detail page or dialog. */
    case Detail = 'detail';
}
