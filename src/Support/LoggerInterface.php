<?php

declare(strict_types=1);

namespace SW\Calendar\Support;

/**
 * Minimal structured logger. Context values must never contain tokens, download URLs, auth headers or event contents.
 */
interface LoggerInterface
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function log(string $level, string $eventCode, string $message, array $context = []): void;
}
