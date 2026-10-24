<?php

declare(strict_types=1);

namespace SW\Calendar\Support;

interface ClockInterface
{
    /** Current time in UTC. */
    public function now(): \DateTimeImmutable;
}
