<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Local;

use SW\Calendar\Domain\Provider\CalendarDefinition;

/** Version 1 of the public, in-process plugin API. No network calls on the read path. */
interface LocalEventSourceInterface
{
    public function name(): string;

    /** @return list<CalendarDefinition> */
    public function calendars(): array;

    /** Source restrictions apply in addition to CloudCalendar's publication/role rules. */
    public function canReadCalendar(string $calendarId, ?int $userId): bool;

    public function query(LocalEventQuery $query): LocalEventPage;

    public function event(string $calendarId, string $id): ?LocalEvent;
}
