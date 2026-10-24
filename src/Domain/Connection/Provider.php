<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Connection;

enum Provider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';
    case Ics = 'ics';
}
