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

final class InMemorySources implements SourceRepositoryInterface, Snapshottable
{
    /** @var array<int, Source> */
    public array $sources = [];

    public function add(Source $source): void
    {
        $this->sources[$source->id] = $source;
    }

    public function find(int $id): ?Source
    {
        return $this->sources[$id] ?? null;
    }

    public function findMany(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            if (isset($this->sources[$id])) {
                $result[$id] = $this->sources[$id];
            }
        }
        return $result;
    }

    public function all(): array
    {
        return array_values($this->sources);
    }

    public function bumpRevision(int $id): void
    {
        $this->replace($id, revision: $this->sources[$id]->revision + 1);
    }

    public function markSuccess(int $id, \DateTimeImmutable $at): void
    {
        $this->replace($id, lastSuccessAt: $at);
    }

    public function setStatus(int $id, SourceStatus $status): void
    {
        $this->replace($id, status: $status);
    }

    private function replace(int $id, ?int $revision = null, ?SourceStatus $status = null, ?\DateTimeImmutable $lastSuccessAt = null): void
    {
        $s = $this->sources[$id];
        $this->sources[$id] = new Source($s->id, $s->connectionId, $s->externalId, $s->name, $s->color, $s->timezone, $s->visibility, $s->roles, $s->settings, $revision ?? $s->revision, $status ?? $s->status, $lastSuccessAt ?? $s->lastSuccessAt);
    }

    public function snapshot(): mixed
    {
        return $this->sources;
    }

    public function restore(mixed $state): void
    {
        $this->sources = $state;
    }
}
