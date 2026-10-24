<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Application\Sync\SyncStateRecord;
use SW\Calendar\Domain\Provider\SyncCursor;
use SW\Calendar\Domain\Provider\SyncWindow;

interface SyncStateStoreInterface
{
    /**
     * Atomic lease (single conditional UPDATE): succeeds only if no lease exists or the existing one expired.
     */
    public function acquireLock(int $sourceId, \DateTimeImmutable $now, \DateTimeImmutable $until): bool;

    public function releaseLock(int $sourceId): void;

    public function load(int $sourceId): SyncStateRecord;

    /**
     * Persists the cursor; only called after the snapshot commit.
     */
    public function saveSuccess(int $sourceId, SyncCursor $cursor, SyncWindow $window, int $generation, string $mode, \DateTimeImmutable $now, \DateTimeImmutable $nextAttempt): void;

    public function saveFailure(int $sourceId, string $errorCode, int $failures, \DateTimeImmutable $nextAttempt): void;

    /** Forces the next run to start a full sync (window change, cursor reset by an admin). */
    public function resetCursor(int $sourceId): void;

    /**
     * @return list<int> Source ids whose next attempt is due.
     */
    public function due(\DateTimeImmutable $now, int $limit): array;
}
