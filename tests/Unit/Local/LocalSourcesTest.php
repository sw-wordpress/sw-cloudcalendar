<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Unit\Local;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Application\Local\EventReference;
use SW\Calendar\Application\Local\LocalEvent;
use SW\Calendar\Application\Local\LocalEventPage;
use SW\Calendar\Application\Local\LocalEventQuery;
use SW\Calendar\Application\Local\LocalEventSourceInterface;
use SW\Calendar\Application\Local\LocalOccurrenceQuery;
use SW\Calendar\Application\Local\LocalSourceRegistry;
use SW\Calendar\Application\Port\OccurrenceQueryInterface;
use SW\Calendar\Application\Render\FilterSchema;
use SW\Calendar\Application\Render\QueryRange;
use SW\Calendar\Application\Render\RangeResolver;
use SW\Calendar\Application\Render\RenderFilter;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Provider\CalendarDefinition;
use SW\Calendar\Domain\Provider\SyncCursor;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Domain\View\DisplayMode;
use SW\Calendar\Infrastructure\Provider\Local\LocalProvider;
use SW\Calendar\Tests\Support\InMemoryConnections;
use SW\Calendar\Tests\Support\InMemorySources;
use SW\Calendar\Tests\Support\TestData;

final class LocalSourcesTest extends TestCase
{
    private function event(string $id = 'instance', string $calendar = 'ext-1', string $start = '2026-11-01T10:00:00Z'): LocalEvent
    {
        $time = new \DateTimeImmutable($start);
        return new LocalEvent($id, $calendar, 'Title', $time, $time->modify('+1 hour'), imageUrl: 'https://example.org/image.jpg', imageAlt: 'Image');
    }

    public function testReferencesAreScopedCanonicalAndBounded(): void
    {
        $ref = EventReference::local(42, 'foreign / recurring:ä');
        self::assertSame(['source' => 42, 'id' => 'foreign / recurring:ä'], EventReference::parse($ref));
        self::assertNotSame($ref, EventReference::local(43, 'foreign / recurring:ä'));
        foreach (['42', 'local.0.YQ', 'local.1.', 'local.1.YR', 'local.1.' . str_repeat('a', 255), 'local.99999999999999999999999999.YQ'] as $invalid) {
            self::assertNull(EventReference::parse($invalid));
        }
    }

    public function testRegistryAndMappedEventsDoNotNeedPersistence(): void
    {
        $adapter = $this->createMock(LocalEventSourceInterface::class);
        $adapter->method('name')->willReturn('Example');
        $registry = new LocalSourceRegistry();
        $registry->register('example-plugin', $adapter);
        self::assertSame($adapter, $registry->get('example-plugin'));
        self::assertNull($registry->get('missing-plugin'));
        self::assertSame([['id' => 'example-plugin', 'name' => 'Example']], $registry->choices());
        $occurrence = $this->event()->occurrence(1);
        self::assertSame(EventReference::local(1, 'instance'), $occurrence->id);
        self::assertSame('https://example.org/image.jpg', $occurrence->imageUrl);
        self::assertSame('Image', $occurrence->imageAlt);
        self::assertSame('UTC', $occurrence->startUtc->getTimezone()->getName());
        $unsafe = new LocalEvent('x', 'ext-1', 'Unsafe', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-02'), url: 'javascript:alert(1)', imageUrl: 'data:text/html,unsafe');
        self::assertSame('', $unsafe->occurrence(1)->externalUrl);
        self::assertSame('', $unsafe->occurrence(1)->imageUrl);
        $this->expectException(\InvalidArgumentException::class);
        $registry->register('example-plugin', $adapter);
    }

    public function testUnsupportedApiVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LocalSourceRegistry())->register('example', $this->createMock(LocalEventSourceInterface::class), 2);
    }

    public function testInvalidEventsAndPagesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalEvent('', 'calendar', 'Invalid', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01'));
    }

    public function testPageTotalCannotBeSmallerThanItsItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalEventPage([$this->event()], 0);
    }

    public function testMixedPagesSortGloballyAndSliceOnlyAfterMerging(): void
    {
        $sources = new InMemorySources();
        $sources->add(TestData::source(1));
        $sources->add(TestData::source(2, connectionId: 2));
        $connections = new InMemoryConnections();
        $connections->add(new Connection(1, Provider::Local, 'Plugin', AuthMode::None, ConnectionStatus::Connected, null, ['adapter' => 'example']));
        $connections->add(new Connection(2, Provider::Ics, 'Feed', AuthMode::None, ConnectionStatus::Connected, null, []));
        $adapter = $this->createMock(LocalEventSourceInterface::class);
        $adapter->method('canReadCalendar')->willReturn(true);
        $adapter->expects(self::once())->method('query')->willReturnCallback(function (LocalEventQuery $q): LocalEventPage {
            self::assertSame(['ext-1'], $q->calendarIds);
            self::assertSame(['excluded'], $q->excludeIds);
            self::assertSame(3, $q->limit);
            self::assertSame(0, $q->offset);
            return new LocalEventPage([$this->event('first'), $this->event('third', start: '2026-11-03T10:00:00Z')], 2);
        });
        $registry = new LocalSourceRegistry();
        $registry->register('example', $adapter);
        $stored = $this->createMock(OccurrenceQueryInterface::class);
        $stored->expects(self::once())->method('find')->willReturn(['items' => [$this->event('second', start: '2026-11-02T10:00:00Z')->occurrence(2)], 'total' => 1]);
        $query = new LocalOccurrenceQuery($stored, $sources, $connections, $registry);
        self::assertTrue($query->isLocal(1));
        self::assertFalse($query->isLocal(2));
        self::assertFalse($query->isLocal(999));
        self::assertTrue($query->canRead($sources->find(1), null));
        self::assertTrue($query->canRead($sources->find(2), null));
        $range = QueryRange::fromLocal(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01'));
        $result = $query->find([1, 2], $range, null, null, [EventReference::local(1, 'excluded'), 99], false, 2, 1, 0);
        self::assertSame(3, $result['total']);
        self::assertSame(['second', 'third'], array_map(static fn ($event): string => $event->providerEventId, $result['items']));
    }

    public function testSingleLocalStreamPushesPaginationAndResolvesDetails(): void
    {
        $sources = new InMemorySources();
        $sources->add(TestData::source());
        $connections = new InMemoryConnections();
        $connection = new Connection(1, Provider::Local, 'Plugin', AuthMode::None, ConnectionStatus::Connected, null, ['adapter' => 'example']);
        $connections->add($connection);
        $registry = new LocalSourceRegistry();
        $adapter = $this->createMock(LocalEventSourceInterface::class);
        $adapter->method('canReadCalendar')->willReturn(false);
        $adapter->method('event')->willReturn($this->event());
        $adapter->method('query')->willReturnCallback(function (LocalEventQuery $q): LocalEventPage {
            self::assertTrue($q->descending);
            self::assertSame(2, $q->limit);
            self::assertSame(100, $q->offset);
            self::assertTrue($q->endedBeforeRangeEnd);
            return new LocalEventPage([], 102);
        });
        $registry->register('example', $adapter);
        $stored = $this->createMock(OccurrenceQueryInterface::class);
        $stored->method('find')->willReturn(['items' => [], 'total' => 0]);
        $stored->method('findById')->with(12)->willReturn(null);
        $query = new LocalOccurrenceQuery($stored, $sources, $connections, $registry);
        self::assertFalse($query->canRead($sources->find(1), null));
        $range = QueryRange::fromLocal(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01'));
        self::assertSame(102, $query->find([1], $range, null, null, [], true, 2, 100, 0, true)['total']);
        self::assertSame('instance', $query->findById(EventReference::local(1, 'instance'))->providerEventId);
        self::assertNull($query->findById(EventReference::local(1, 'other')));
        self::assertNull($query->findById('invalid'));
        self::assertNull($query->findById('12'));
        $connections->setStatus(1, ConnectionStatus::Disconnected);
        self::assertFalse($query->canRead($sources->find(1), null));
        self::assertNull($query->findById(EventReference::local(1, 'instance')));
    }

    public function testLocalFacadeListsCalendarsButRefusesSynchronization(): void
    {
        $registry = new LocalSourceRegistry();
        $adapter = $this->createMock(LocalEventSourceInterface::class);
        $adapter->method('name')->willReturn('Example');
        $adapter->method('calendars')->willReturn([new CalendarDefinition('one', 'Calendar')]);
        $registry->register('example', $adapter);
        $facade = new LocalProvider($registry);
        $connection = new Connection(1, Provider::Local, 'Plugin', AuthMode::None, ConnectionStatus::Connected, null, ['adapter' => 'example']);
        self::assertTrue($facade->capabilities($connection)->listCalendars);
        self::assertFalse($facade->capabilities($connection)->fileAttachments);
        self::assertTrue($facade->testConnection($connection)->ok);
        self::assertSame('one', $facade->listCalendars($connection)[0]->externalId);
        self::assertFalse($facade->testConnection(new Connection(2, Provider::Local, 'Missing', AuthMode::None, ConnectionStatus::Connected, null, []))->ok);
        $this->expectException(CalendarException::class);
        $facade->fetchChanges($connection, TestData::source(), SyncCursor::empty(), new SyncWindow(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01')));
    }

    public function testPresentationOptionsAndMonthListRangeAreValidated(): void
    {
        $parsed = (new FilterSchema())->normalize(['month' => '2026-11', 'legend' => 'true', 'color_titles' => 'true', 'columns' => 2, 'day_limit' => 3, 'navigation_group' => 'events', 'exclude' => EventReference::local(1, 'item')]);
        self::assertTrue($parsed->isValid());
        $filter = $parsed->filter->withDefaults(new RenderFilter());
        self::assertTrue($filter->legend);
        self::assertTrue($filter->colorTitles);
        self::assertSame(2, $filter->toArray()['columns']);
        self::assertSame(3, $filter->dayLimit);
        self::assertSame('events', $filter->navigationGroup);
        $range = (new RangeResolver())->resolve($filter, DisplayMode::List, new \DateTimeZone('Europe/Berlin'), new \DateTimeImmutable('2026-10-01'));
        self::assertSame('2026-10-31 23:00', $range->startUtc->format('Y-m-d H:i'));
        self::assertSame('2026-11-30 23:00', $range->endUtc->format('Y-m-d H:i'));
        self::assertCount(4, (new FilterSchema())->normalize(['columns' => 0, 'day_limit' => 21, 'navigation_group' => '<script>', 'exclude' => 'local.bad'])->errors);
    }

    public function testAdapterFailuresAreClosedAndLoggedWithoutLeakingExceptionContents(): void
    {
        $sources = new InMemorySources();
        $sources->add(TestData::source());
        $connections = new InMemoryConnections();
        $connections->add(new Connection(1, Provider::Local, 'Plugin', AuthMode::None, ConnectionStatus::Connected, null, ['adapter' => 'example']));
        $registry = new LocalSourceRegistry();
        $adapter = $this->createMock(LocalEventSourceInterface::class);
        $failure = new \RuntimeException('Secret event data must not be logged');
        $adapter->method('canReadCalendar')->willThrowException($failure);
        $adapter->method('query')->willThrowException($failure);
        $adapter->method('event')->willThrowException($failure);
        $registry->register('example', $adapter);
        $logger = new \SW\Calendar\Support\NullLogger();
        $query = new LocalOccurrenceQuery($this->createMock(OccurrenceQueryInterface::class), $sources, $connections, $registry, $logger);
        self::assertFalse($query->canRead($sources->find(1), null));
        self::assertNull($query->findById(EventReference::local(1, 'item')));
        $range = QueryRange::fromLocal(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01'));
        self::assertSame(['items' => [], 'total' => 0], $query->find([1], $range, null, null, [], false, 10, 0, 0));
        self::assertCount(3, $logger->records);
        self::assertStringNotContainsString('Secret', (string) json_encode($logger->records));
    }

    public function testMissingAdapterAndUnboundedMixedPagesAreHandled(): void
    {
        $sources = new InMemorySources();
        $sources->add(TestData::source());
        $sources->add(TestData::source(2, connectionId: 2));
        $connections = new InMemoryConnections();
        $connections->add(new Connection(1, Provider::Local, 'Missing', AuthMode::None, ConnectionStatus::Connected, null, ['adapter' => 'missing']));
        $connections->add(new Connection(2, Provider::Ics, 'Feed', AuthMode::None, ConnectionStatus::Connected, null, []));
        $query = new LocalOccurrenceQuery($this->createMock(OccurrenceQueryInterface::class), $sources, $connections, new LocalSourceRegistry());
        $range = QueryRange::fromLocal(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01'));
        self::assertSame(['items' => [], 'total' => 0], $query->find([1], $range, null, null, [], false, 10, 0, 0));
        self::assertFalse($query->canRead($sources->find(1), null));
        self::assertNull($query->findById(EventReference::local(999, 'item')));
        $this->expectException(CalendarException::class);
        $query->find([1, 2], $range, null, null, [], false, 100, 10000, 0);
    }
}
