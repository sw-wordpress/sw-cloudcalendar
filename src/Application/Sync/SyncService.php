<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Sync;

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
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Job\JobType;
use SW\Calendar\Domain\Provider\SyncCursor;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Domain\Source\SourceStatus;
use SW\Calendar\Support\ClockInterface;
use SW\Calendar\Support\LoggerInterface;
use SW\Calendar\Support\Redactor;

/**
 * Synchronisation run of one source (SPECIFICATIONS.md §7.1):
 *
 * 1. atomic per source lease; 2. cursor/window/capabilities decide full or delta; 3. ALL pages are fetched into an
 * isolated staging area; 4. local expansion; 5. one transaction applies staging, swaps the occurrence generation and
 * stores the cursor (6.); 7. attachment jobs from the committed state; 8. cache invalidation, diagnostics, release.
 *
 * Any failure before the commit leaves occurrences AND cursor untouched: visitors keep the last valid data.
 */
final class SyncService
{
    public const LOCK_SECONDS = 900;
    public const MAX_PAGES = 1000;
    public const MAX_BACKOFF_SECONDS = 21600;

    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly ConnectionRepositoryInterface $connections,
        private readonly ProviderRegistryInterface $providers,
        private readonly ProviderItemStoreInterface $items,
        private readonly OccurrenceStoreInterface $occurrences,
        private readonly SyncStateStoreInterface $state,
        private readonly SyncRunStoreInterface $runs,
        private readonly AttachmentStoreInterface $attachments,
        private readonly JobQueueInterface $jobs,
        private readonly TransactionInterface $transaction,
        private readonly CacheInvalidatorInterface $invalidator,
        private readonly OccurrenceBuilder $builder,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $maxRunSeconds = 240,
    ) {
    }

    public function sync(int $sourceId, bool $forceFull = false): SyncReport
    {
        $metrics = new SyncMetrics();
        $source = $this->sources->find($sourceId);
        if ($source === null || !$source->status->isSyncable() || !$source->settings->syncEnabled) {
            return new SyncReport($sourceId, SyncReport::SKIPPED, $metrics, null, 'Source is not active.');
        }
        $connection = $this->connections->find($source->connectionId);
        $now = $this->clock->now();
        if (!$this->state->acquireLock($sourceId, $now, $now->modify('+' . self::LOCK_SECONDS . ' seconds'))) {
            return new SyncReport($sourceId, SyncReport::LOCKED, $metrics, ErrorCode::Locked->value, 'Another synchronisation of this source is running.');
        }

        $runId = null;
        $started = microtime(true);
        try {
            if ($connection === null || $connection->status === ConnectionStatus::Disabled) {
                throw new CalendarException(ErrorCode::AuthRequired, 'The connection of this source is missing or disabled.');
            }
            if ($connection->status === ConnectionStatus::ReauthRequired) {
                throw new CalendarException(ErrorCode::AuthRequired, 'The connection must be re-authorized.');
            }
            $provider = $this->providers->get($connection->provider);
            $metrics->provider = $connection->provider->value;
            $record = $this->state->load($sourceId);
            $window = SyncWindow::around($now, $source->settings->pastDays, $source->settings->futureDays);
            $cursor = $forceFull ? SyncCursor::empty() : $record->cursor;
            if (!$cursor->isEmpty() && $cursor->boundWindow !== null && !$cursor->boundWindow->equals($window)) {
                // The cursor is bound to another window (Graph delta): start a new track and reconcile completely.
                $cursor = SyncCursor::empty();
            }
            $metrics->mode = $cursor->isEmpty() ? 'full' : 'delta';
            $runId = $this->runs->start($sourceId, $metrics->mode, $now);

            [$finalCursor, $fullSnapshot] = $this->fetchAll($provider, $connection, $source, $cursor, $window, $runId, $metrics, $started);

            $generation = $record->generation + 1;
            [$diff, $occurrenceList] = $this->transaction->run(function () use ($runId, $sourceId, $fullSnapshot, $source, $window, $generation, $finalCursor, $metrics, $now): array {
                $this->items->applyStaging($runId, $sourceId, $fullSnapshot);
                $occurrences = $this->builder->build($sourceId, $this->items->loadItems($sourceId), $window, $source->settings);
                $diff = $this->occurrences->replaceSnapshot($sourceId, $occurrences, $generation);
                $nextAttempt = $now->modify('+' . $source->settings->syncIntervalMinutes . ' minutes');
                $this->state->saveSuccess($sourceId, $finalCursor, $window, $generation, $metrics->mode, $now, $nextAttempt);
                if ($diff->hasChanges()) {
                    $this->sources->bumpRevision($sourceId);
                }
                $this->sources->markSuccess($sourceId, $now);
                if ($source->status === SourceStatus::Error) {
                    $this->sources->setStatus($sourceId, SourceStatus::Active);
                }
                $metrics->warnings = array_merge($metrics->warnings, $this->builder->warnings);
                return [$diff, $occurrences];
            });
            $metrics->created = $diff->created;
            $metrics->updated = $diff->updated;
            $metrics->deleted = $diff->deleted;

            $this->planAttachments($source, $occurrenceList, $diff, $metrics);
            if ($diff->hasChanges() || $metrics->attachmentsRevoked > 0) {
                $this->invalidator->sourceChanged($sourceId);
            }
            $metrics->durationMs = (int) round((microtime(true) - $started) * 1000);
            $this->runs->finish($runId, SyncReport::SUCCESS, $metrics, null, null, $this->clock->now());
            $this->logger->log('info', 'sync_success', 'Source synchronised.', [
                'source_id' => $sourceId,
                'mode' => $metrics->mode,
                'created' => $diff->created,
                'updated' => $diff->updated,
                'deleted' => $diff->deleted,
            ]);
            return new SyncReport($sourceId, SyncReport::SUCCESS, $metrics, null, null, $now->modify('+' . $source->settings->syncIntervalMinutes . ' minutes'));
        } catch (\Throwable $e) {
            if ($runId !== null) {
                $this->items->discardStaging($runId);
            }
            return $this->fail($source, $runId, $e, $metrics, $record ?? null, $started);
        } finally {
            $this->state->releaseLock($sourceId);
        }
    }

    /**
     * @return array{0: SyncCursor, 1: bool} Final cursor and whether the staged data is a full snapshot.
     */
    private function fetchAll(
        \SW\Calendar\Domain\Provider\CalendarProviderInterface $provider,
        \SW\Calendar\Domain\Connection\Connection $connection,
        Source $source,
        SyncCursor $cursor,
        SyncWindow $window,
        int $runId,
        SyncMetrics $metrics,
        float $started,
    ): array {
        $pageCursor = $cursor;
        $restarted = false;
        $fullSnapshot = $cursor->isEmpty();
        while (true) {
            if ($metrics->pages >= self::MAX_PAGES) {
                throw new CalendarException(ErrorCode::LimitExceeded, 'The provider returned too many pages.');
            }
            if (microtime(true) - $started > $this->maxRunSeconds) {
                throw new CalendarException(ErrorCode::FetchFailed, 'The synchronisation exceeded its maximum run time.');
            }
            try {
                $page = $provider->fetchChanges($connection, $source, $pageCursor, $window);
            } catch (CalendarException $e) {
                if ($e->errorCode === ErrorCode::SyncCursorInvalid && !$restarted) {
                    // Expired sync token / delta link (Google 410, Graph resync): rebuild from scratch, once.
                    $this->items->discardStaging($runId);
                    $restarted = true;
                    $fullSnapshot = true;
                    $metrics->mode = 'full';
                    $metrics->pages = 0;
                    $metrics->eventsRead = 0;
                    $metrics->warnings[] = 'Sync cursor expired; full resynchronisation performed.';
                    $pageCursor = SyncCursor::empty();
                    continue;
                }
                throw $e;
            }
            $metrics->pages++;
            if ($page->notModified) {
                $metrics->notModified = true;
                $fullSnapshot = false;
                return [$page->finalCursor ?? $cursor, false];
            }
            $fullSnapshot = $fullSnapshot || $page->fullSnapshot;
            $metrics->eventsRead += count($page->items);
            $metrics->removedRead += count($page->removedItemIds);
            $this->items->stage($runId, $source->id, $page->items, $page->removedItemIds);
            if ($page->isLast()) {
                return [$page->finalCursor ?? SyncCursor::empty(), $fullSnapshot];
            }
            $pageCursor = $page->nextPage ?? SyncCursor::empty();
        }
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function planAttachments(Source $source, array $occurrences, SnapshotDiff $diff, SyncMetrics $metrics): void
    {
        $policy = $source->settings->attachments;
        if (!$policy->syncAttachments) {
            $metrics->attachmentsRevoked = $this->attachments->revokeSource($source->id);
            return;
        }
        $byOccurrence = [];
        foreach ($occurrences as $occurrence) {
            $id = $diff->idsByKey[$occurrence->occurrenceKey] ?? null;
            if ($id !== null) {
                $byOccurrence[$id] = ['provider_event_id' => $occurrence->providerEventId, 'attachments' => $occurrence->attachments];
            }
        }
        $now = $this->clock->now();
        $result = $this->attachments->reconcile($source->id, $byOccurrence, $now, $policy->refreshHours);
        $metrics->attachmentsRevoked = $result['revoked'];
        foreach ($result['pending'] as $pending) {
            $this->jobs->enqueue(
                JobType::SyncAttachment,
                $source->id,
                ['ref_hash' => $pending['ref_hash'], 'provider_event_id' => $pending['provider_event_id']],
                hash('sha256', 'sync_attachment|' . $source->id . '|' . $pending['ref_hash'] . '|' . $pending['version']),
                $now,
            );
            $metrics->attachmentsPlanned++;
        }
    }

    private function fail(Source $source, ?int $runId, \Throwable $e, SyncMetrics $metrics, ?SyncStateRecord $record, float $started): SyncReport
    {
        $now = $this->clock->now();
        $code = $e instanceof CalendarException ? $e->errorCode : ErrorCode::FetchFailed;
        $message = $e instanceof CalendarException ? Redactor::text($e->getMessage()) : 'Unexpected error during synchronisation.';
        $record ??= $this->state->load($source->id);
        $failures = $record->failures + 1;
        $retryAfter = $e instanceof CalendarException ? $e->retryAfterSeconds : null;
        $nextAttempt = $now->modify('+' . $this->backoffSeconds($source->settings->syncIntervalMinutes, $failures, $retryAfter) . ' seconds');

        if ($code === ErrorCode::AuthRequired) {
            $this->connections->setStatus($source->connectionId, ConnectionStatus::ReauthRequired);
        }
        // The source stays readable with its last valid data (status "error" is readable).
        if ($source->status === SourceStatus::Active) {
            $this->sources->setStatus($source->id, SourceStatus::Error);
        }
        $this->state->saveFailure($source->id, $code->value, $failures, $nextAttempt);
        $metrics->durationMs = (int) round((microtime(true) - $started) * 1000);
        if ($runId !== null) {
            $this->runs->finish($runId, SyncReport::FAILED, $metrics, $code->value, $message, $now);
        }
        $this->logger->log('error', 'sync_failed', $message, [
            'source_id' => $source->id,
            'error_code' => $code->value,
            'exception' => $e instanceof CalendarException ? null : get_class($e),
            'detail' => $e instanceof CalendarException ? null : Redactor::text($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), 240),
        ]);
        return new SyncReport($source->id, SyncReport::FAILED, $metrics, $code->value, $message, $nextAttempt);
    }

    /**
     * Exponential backoff with jitter, capped; a provider supplied Retry-After is respected as the minimum.
     */
    public function backoffSeconds(int $intervalMinutes, int $failures, ?int $retryAfter): int
    {
        $base = max(60, min($intervalMinutes * 60, 900));
        $delay = min(self::MAX_BACKOFF_SECONDS, $base * (2 ** min(10, max(0, $failures - 1))));
        $delay += random_int(0, (int) max(1, $delay / 10));
        if ($retryAfter !== null) {
            $delay = max($delay, min(self::MAX_BACKOFF_SECONDS, $retryAfter));
        }
        return (int) $delay;
    }
}
