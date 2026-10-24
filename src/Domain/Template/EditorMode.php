<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Template;

enum EditorMode: string
{
    case Visual = 'visual';
    case Html = 'html';
}
