<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

interface DateFormatterInterface
{
    /**
     * Localized formatting with a PHP date() format string.
     */
    public function format(\DateTimeInterface $time, \DateTimeZone $tz, string $format): string;

    public function defaultDateFormat(): string;

    public function defaultTimeFormat(): string;
}
