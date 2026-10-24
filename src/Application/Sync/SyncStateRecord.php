<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Sync;

use SW\Calendar\Domain\Provider\SyncCursor;

final class SyncStateRecord
{
    public function __construct(
        public readonly SyncCursor $cursor,
        public readonly int $generation,
        public readonly int $failures,
        public readonly ?\DateTimeImmutable $lastSuccessAt,
        public readonly ?string $lastErrorCode,
        public readonly ?\DateTimeImmutable $nextAttemptAt = null,
        public readonly string $status = 'idle',
        public readonly ?\DateTimeImmutable $lockedUntil = null,
    ) {
    }

    public static function initial(): self
    {
        return new self(SyncCursor::empty(), 0, 0, null, null);
    }
}
