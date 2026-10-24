<?php

declare(strict_types=1);

namespace SW\Calendar\Support;

final class NullLogger implements LoggerInterface
{
    /** @var list<array{level: string, code: string, message: string, context: array<string, scalar|null>}> */
    public array $records = [];

    public function log(string $level, string $eventCode, string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'code' => $eventCode, 'message' => $message, 'context' => $context];
    }
}
