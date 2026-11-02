<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Local;

final class LocalEventPage
{
    /** @param list<LocalEvent> $items */
    public function __construct(public readonly array $items, public readonly int $total)
    {
        if ($total < count($items)) {
            throw new \InvalidArgumentException('The total must include all matching events.');
        }
    }
}
