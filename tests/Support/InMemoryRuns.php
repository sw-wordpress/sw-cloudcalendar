<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Support;

use SW\Calendar\Application\Attachments\StoredFile;
use SW\Calendar\Application\Port\AttachmentStoreInterface;
use SW\Calendar\Application\Port\CacheInvalidatorInterface;
use SW\Calendar\Application\Port\ConnectionRepositoryInterface;
use SW\Calendar\Application\Port\JobQueueInterface;
use SW\Calendar\Application\Port\OccurrenceStoreInterface;
use SW\Calendar\Application\Port\ProviderItemStoreInterface;
use SW\Calendar\Application\Port\ProviderRegistryInterface;
use SW\Calendar\Application\Port\SourceRepositoryInterface;
use SW\Calendar\Application\Port\SyncRunStoreInterface;
use SW\Calendar\Application\Port\SyncStateStoreInterface;
use SW\Calendar\Application\Port\TransactionInterface;
use SW\Calendar\Application\Port\UserContextInterface;
use SW\Calendar\Application\Sync\SnapshotDiff;
use SW\Calendar\Application\Sync\SyncMetrics;
use SW\Calendar\Application\Sync\SyncStateRecord;
use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\ProviderEvent;
use SW\Calendar\Domain\Job\Job;
use SW\Calendar\Domain\Job\JobType;
use SW\Calendar\Domain\Provider\CalendarProviderInterface;
use SW\Calendar\Domain\Provider\SyncCursor;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Domain\Source\SourceStatus;

final class InMemoryRuns implements SyncRunStoreInterface
{
    /** @var array<int, array{source: int, mode: string, status: ?string, metrics: ?SyncMetrics, error: ?string}> */
    public array $runs = [];

    public function start(int $sourceId, string $mode, \DateTimeImmutable $now): int
    {
        $this->runs[] = ['source' => $sourceId, 'mode' => $mode, 'status' => null, 'metrics' => null, 'error' => null];
        return count($this->runs);
    }

    public function finish(int $runId, string $status, SyncMetrics $metrics, ?string $errorCode, ?string $message, \DateTimeImmutable $now): void
    {
        $this->runs[$runId - 1]['status'] = $status;
        $this->runs[$runId - 1]['metrics'] = clone $metrics;
        $this->runs[$runId - 1]['error'] = $errorCode;
    }
}
