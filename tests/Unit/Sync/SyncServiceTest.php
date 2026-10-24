<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Sync;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Sync\OccurrenceBuilder;
use SW\Calendar\Application\Sync\RecurrenceExpander;
use SW\Calendar\Application\Sync\SyncReport;
use SW\Calendar\Application\Sync\SyncService;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Provider\ProviderAttachmentDefinition;
use SW\Calendar\Domain\Provider\SyncCursor;
use SW\Calendar\Domain\Provider\SyncPage;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Domain\Source\SourceStatus;
use SW\Calendar\Support\FrozenClock;
use SW\Calendar\Support\NullLogger;
use SW\Calendar\Tests\Support\InMemoryAttachments;
use SW\Calendar\Tests\Support\InMemoryConnections;
use SW\Calendar\Tests\Support\InMemoryItems;
use SW\Calendar\Tests\Support\InMemoryJobs;
use SW\Calendar\Tests\Support\InMemoryOccurrences;
use SW\Calendar\Tests\Support\InMemoryRuns;
use SW\Calendar\Tests\Support\InMemorySources;
use SW\Calendar\Tests\Support\InMemorySyncState;
use SW\Calendar\Tests\Support\InMemoryTransaction;
use SW\Calendar\Tests\Support\PlainSanitizer;
use SW\Calendar\Tests\Support\RecordingInvalidator;
use SW\Calendar\Tests\Support\ScriptedProvider;
use SW\Calendar\Tests\Support\StaticProviders;
use SW\Calendar\Tests\Support\TestData;

final class SyncServiceTest extends TestCase
{
    private InMemorySources $sources;
    private InMemoryConnections $connections;
    private ScriptedProvider $provider;
    private InMemoryItems $items;
    private InMemoryOccurrences $occurrences;
    private InMemorySyncState $state;
    private InMemoryRuns $runs;
    private InMemoryAttachments $attachments;
    private InMemoryJobs $jobs;
    private RecordingInvalidator $invalidator;
    private FrozenClock $clock;
    private SyncService $service;

    protected function setUp(): void
    {
        $this->sources = new InMemorySources();
        $this->sources->add(TestData::source(1, settings: SourceSettings::fromArray(['attachments' => ['sync_attachments' => true]])));
        $this->connections = new InMemoryConnections();
        $this->connections->add(new Connection(1, Provider::Google, 'G', AuthMode::OAuth, ConnectionStatus::Connected, null, []));
        $this->provider = new ScriptedProvider();
        $this->items = new InMemoryItems();
        $this->occurrences = new InMemoryOccurrences();
        $this->state = new InMemorySyncState();
        $this->runs = new InMemoryRuns();
        $this->attachments = new InMemoryAttachments();
        $this->jobs = new InMemoryJobs();
        $this->invalidator = new RecordingInvalidator();
        $this->clock = new FrozenClock('2026-10-24T12:00:00Z');
        $this->service = $this->makeService(new OccurrenceBuilder(new RecurrenceExpander(), new PlainSanitizer()));
    }

    private function makeService(OccurrenceBuilder $builder): SyncService
    {
        return new SyncService(
            $this->sources,
            $this->connections,
            new StaticProviders($this->provider),
            $this->items,
            $this->occurrences,
            $this->state,
            $this->runs,
            $this->attachments,
            $this->jobs,
            new InMemoryTransaction($this->items, $this->occurrences, $this->state, $this->sources),
            $this->invalidator,
            $builder,
            $this->clock,
            new NullLogger(),
        );
    }

    private function page(array $items, bool $last, bool $full = true, array $removed = [], array $cursor = ['token' => 'final']): SyncPage
    {
        return new SyncPage($items, $removed, $full, $last ? null : new SyncCursor(['page' => uniqid()]), $last ? new SyncCursor($cursor) : null);
    }

    private function initialSync(): void
    {
        $this->provider->script = [
            $this->page([TestData::event('a', '2026-11-01 10:00', '2026-11-01 11:00', title: 'A')], false),
            $this->page([TestData::event('b', '2026-11-02 10:00', '2026-11-02 11:00', title: 'B')], true),
        ];
        self::assertTrue($this->service->sync(1)->isSuccess());
    }

    public function testMultiPageFullSyncCommitsAllPagesAndCursor(): void
    {
        $this->initialSync();
        self::assertSame(['A@11-01', 'B@11-02'], $this->occurrences->titles(1));
        self::assertSame('final', $this->state->load(1)->cursor->get('token'));
        self::assertSame(2, $this->sources->find(1)->revision, 'Revision bumped on change');
        self::assertSame([1], $this->invalidator->changed);
        self::assertSame('success', $this->runs->runs[0]['status']);
        self::assertSame(2, $this->runs->runs[0]['metrics']->pages);
    }

    public function testFailureOnPageThreeOfFiveKeepsDataAndCursor(): void
    {
        $this->initialSync();
        $this->provider->script = [
            $this->page([TestData::event('c', '2026-11-03 10:00', '2026-11-03 11:00', title: 'C')], false, false),
            $this->page([], false, false, ['a']),
            new CalendarException(ErrorCode::FetchFailed, 'boom'),
        ];
        $report = $this->service->sync(1);
        self::assertSame(SyncReport::FAILED, $report->status);
        self::assertSame(['A@11-01', 'B@11-02'], $this->occurrences->titles(1), 'Visible data unchanged');
        self::assertSame('final', $this->state->load(1)->cursor->get('token'), 'Cursor unchanged');
        self::assertSame([], $this->items->staging, 'Staging discarded');
        self::assertSame(SourceStatus::Error, $this->sources->find(1)->status, 'Source flagged but still readable');
        self::assertTrue($this->sources->find(1)->isReadable());
        self::assertSame(1, $this->state->load(1)->failures);
    }

    public function testDeltaSyncUsesStoredCursorAndAppliesRemovals(): void
    {
        $this->initialSync();
        $this->provider->script = [
            $this->page([TestData::event('c', '2026-11-03 10:00', '2026-11-03 11:00', title: 'C')], true, false, ['a'], ['token' => 'next']),
        ];
        self::assertTrue($this->service->sync(1)->isSuccess());
        self::assertSame('final', $this->provider->calls[2]->get('token'));
        self::assertSame(['B@11-02', 'C@11-03'], $this->occurrences->titles(1));
        self::assertSame('delta', $this->runs->runs[1]['mode']);
    }

    public function testExpiredCursorTriggersFullRebuild(): void
    {
        $this->initialSync();
        $this->provider->script = [
            new CalendarException(ErrorCode::SyncCursorInvalid, 'gone'),
            $this->page([TestData::event('z', '2026-11-05 10:00', '2026-11-05 11:00', title: 'Z')], true, true, [], ['token' => 'fresh']),
        ];
        $report = $this->service->sync(1);
        self::assertTrue($report->isSuccess());
        self::assertTrue($this->provider->calls[3]->isEmpty(), 'Restarted without cursor');
        self::assertSame(['Z@11-05'], $this->occurrences->titles(1), 'Full snapshot replaces everything');
        self::assertSame('fresh', $this->state->load(1)->cursor->get('token'));
        self::assertSame('full', $report->metrics->mode);
    }

    public function testUnchangedDataDoesNotBumpRevision(): void
    {
        $this->initialSync();
        $this->provider->script = [$this->page([], true, false)];
        self::assertTrue($this->service->sync(1)->isSuccess());
        self::assertSame(2, $this->sources->find(1)->revision);
        self::assertSame([1], $this->invalidator->changed);
    }

    public function testCommitFailureRollsBackEverything(): void
    {
        $this->initialSync();
        $service = $this->makeService(new OccurrenceBuilder(new RecurrenceExpander(), new PlainSanitizer(), 2));
        $this->provider->script = [$this->page([TestData::event('c', '2026-11-03 10:00', '2026-11-03 11:00', title: 'C')], true, false, [], ['token' => 'never'])];
        $report = $service->sync(1);
        self::assertSame(SyncReport::FAILED, $report->status);
        self::assertSame(ErrorCode::LimitExceeded->value, $report->errorCode);
        self::assertSame(['A@11-01', 'B@11-02'], $this->occurrences->titles(1));
        self::assertSame('final', $this->state->load(1)->cursor->get('token'));
        self::assertCount(2, iterator_to_array((function () {
            yield from $this->items->loadItems(1);
        })()), 'Items rolled back');
    }

    public function testLockPreventsParallelRunAndExpiredLockIsTakenOver(): void
    {
        $now = $this->clock->now();
        self::assertTrue($this->state->acquireLock(1, $now, $now->modify('+10 minutes')));
        self::assertSame(SyncReport::LOCKED, $this->service->sync(1)->status);
        $this->clock->advance('+11 minutes');
        $this->provider->script = [$this->page([], true)];
        self::assertTrue($this->service->sync(1)->isSuccess());
    }

    public function testRateLimitRespectsRetryAfter(): void
    {
        $this->provider->script = [new CalendarException(ErrorCode::RateLimited, '429', 7200)];
        $report = $this->service->sync(1);
        self::assertGreaterThanOrEqual(7200, $report->nextAttempt->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    public function testAuthFailureMarksConnectionForReauthorization(): void
    {
        $this->provider->script = [new CalendarException(ErrorCode::AuthRequired, 'invalid_grant')];
        $this->service->sync(1);
        self::assertSame(ConnectionStatus::ReauthRequired, $this->connections->find(1)->status);
        // Next run does not call the provider at all.
        $this->service->sync(1);
        self::assertCount(1, $this->provider->calls);
    }

    public function testAttachmentJobsAreIdempotentForSeriesInstances(): void
    {
        $definition = new ProviderAttachmentDefinition('file', 'drive-1', 'plan.pdf', 'application/pdf', 100, 'v1');
        $master = TestData::event('s', '2026-11-01 10:00', '2026-11-01 11:00', rrule: 'FREQ=WEEKLY;COUNT=4');
        $master = new \SW\Calendar\Domain\Event\ProviderEvent($master->itemId, null, null, 'S', '', '', '', '', $master->start, $master->end, false, 'Europe/Berlin', $master->status, $master->rrule, [], [], null, false, [$definition]);
        $this->provider->script = [$this->page([$master], true)];
        self::assertTrue($this->service->sync(1)->isSuccess());
        self::assertCount(4, $this->attachments->reconciled);
        self::assertCount(1, $this->jobs->jobs, 'One download job for the shared file');
    }

    public function testInactiveSourcesAreSkipped(): void
    {
        $this->sources->setStatus(1, SourceStatus::Disabled);
        self::assertSame(SyncReport::SKIPPED, $this->service->sync(1)->status);
        self::assertSame([], $this->provider->calls);
    }
}
