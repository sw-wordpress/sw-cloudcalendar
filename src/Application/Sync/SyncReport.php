<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Sync;

final class SyncReport
{
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    public const LOCKED = 'locked';

    public function __construct(
        public readonly int $sourceId,
        public readonly string $status,
        public readonly SyncMetrics $metrics,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
        public readonly ?\DateTimeImmutable $nextAttempt = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }
}
