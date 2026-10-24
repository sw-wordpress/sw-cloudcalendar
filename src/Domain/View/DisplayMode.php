<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\View;

enum DisplayMode: string
{
    case Month = 'month';
    case List = 'list';
    case Cards = 'cards';

    /** Result oriented modes honour `limit`; the month grid always shows the complete month. */
    public function isResultOriented(): bool
    {
        return $this !== self::Month;
    }
}
