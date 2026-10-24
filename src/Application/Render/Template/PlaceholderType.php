<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

enum PlaceholderType: string
{
    /** Plain text: escaped for HTML text or attribute context. */
    case Text = 'text';
    /** URL: scheme checked and escaped; the only type allowed in href/src. */
    case Url = 'url';
    /** #rrggbb colour: the only type allowed inside a style attribute. */
    case Color = 'color';
    /** Plugin generated trusted HTML component: only allowed as a standalone text node. */
    case Html = 'html';
    /** Flag used for sections; renders "1" or "" if used as a variable. */
    case Flag = 'flag';
}
