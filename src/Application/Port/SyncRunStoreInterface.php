<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Application\Sync\SyncMetrics;

interface SyncRunStoreInterface
{
    public function start(int $sourceId, string $mode, \DateTimeImmutable $now): int;

    public function finish(int $runId, string $status, SyncMetrics $metrics, ?string $errorCode, ?string $message, \DateTimeImmutable $now): void;
}
