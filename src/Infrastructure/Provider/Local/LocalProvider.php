<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Provider\Local;

use SW\Calendar\Application\Local\LocalEventSourceInterface;
use SW\Calendar\Application\Local\LocalSourceRegistry;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Provider\AttachmentInspection;
use SW\Calendar\Domain\Provider\CalendarProviderInterface;
use SW\Calendar\Domain\Provider\ConnectionTestResult;
use SW\Calendar\Domain\Provider\DownloadResult;
use SW\Calendar\Domain\Provider\DownloadTarget;
use SW\Calendar\Domain\Provider\ProviderAttachmentReference;
use SW\Calendar\Domain\Provider\ProviderCapabilities;
use SW\Calendar\Domain\Provider\ProviderEventReference;
use SW\Calendar\Domain\Provider\SyncCursor;
use SW\Calendar\Domain\Provider\SyncPage;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Domain\Source\Source;

/** Administrative facade only. Live reads use LocalOccurrenceQuery, never the sync pipeline. */
final class LocalProvider implements CalendarProviderInterface
{
    public function __construct(private readonly LocalSourceRegistry $registry)
    {
    }

    public function capabilities(Connection $connection): ProviderCapabilities
    {
        return new ProviderCapabilities(true, false, false, false, false, false, false);
    }

    public function testConnection(Connection $connection): ConnectionTestResult
    {
        $adapter = $this->registry->get((string) $connection->setting('adapter', ''));
        return new ConnectionTestResult($adapter !== null, $adapter?->name() ?? 'Local source plugin unavailable.', $adapter === null ? ErrorCode::ProviderUnsupported : null);
    }

    public function listCalendars(Connection $connection): array
    {
        return $this->adapter($connection)->calendars();
    }

    public function fetchChanges(Connection $connection, Source $source, SyncCursor $cursor, SyncWindow $window): SyncPage
    {
        throw new CalendarException(ErrorCode::ProviderUnsupported, 'Local sources are queried directly, not synchronized.');
    }

    public function listAttachments(Connection $connection, Source $source, ProviderEventReference $event): array
    {
        return [];
    }

    public function inspectAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment): AttachmentInspection
    {
        throw new CalendarException(ErrorCode::AttachmentUnsupported, 'Local file downloads are not supported.');
    }

    public function downloadAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment, DownloadTarget $target): DownloadResult
    {
        throw new CalendarException(ErrorCode::AttachmentUnsupported, 'Local file downloads are not supported.');
    }

    private function adapter(Connection $connection): LocalEventSourceInterface
    {
        return $this->registry->get((string) $connection->setting('adapter', '')) ?? throw new CalendarException(ErrorCode::ProviderUnsupported, 'Local source plugin unavailable.');
    }
}
