<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Domain\View\CalendarView;

interface ViewRepositoryInterface
{
    public function find(int $id): ?CalendarView;
}
