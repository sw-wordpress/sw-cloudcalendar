<?php

declare(strict_types=1);

namespace SW\Calendar\Support;

/**
 * Deterministic clock for tests, previews and CLI dry runs.
 */
final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now)
    {
        $this->now = (is_string($now) ? new \DateTimeImmutable($now) : $now)->setTimezone(new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
