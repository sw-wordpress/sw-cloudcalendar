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

final class StaticUsers implements UserContextInterface
{
    /**
     * @param array<int, list<string>> $roles
     */
    public function __construct(private readonly array $roles = [])
    {
    }

    public function rolesOf(?int $userId): ?array
    {
        if ($userId === null || $userId === 0 || !isset($this->roles[$userId])) {
            return null;
        }
        return $this->roles[$userId];
    }
}
