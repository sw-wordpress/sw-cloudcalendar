<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Source\Source;

/**
 * Read-only provider adapter (SPECIFICATIONS.md §5). Adapters never write to the provider.
 */
interface CalendarProviderInterface
{
    public function capabilities(Connection $connection): ProviderCapabilities;

    public function testConnection(Connection $connection): ConnectionTestResult;

    /**
     * @return CalendarDefinition[]
     * @throws CalendarException
     */
    public function listCalendars(Connection $connection): array;

    /**
     * Fetches one page. The caller passes `SyncCursor::empty()` for a full sync, the stored cursor for a delta sync and
     * `SyncPage::$nextPage` for continuation pages.
     *
     * @throws CalendarException SYNC_CURSOR_INVALID when a delta cursor expired (caller restarts with a full sync).
     */
    public function fetchChanges(Connection $connection, Source $source, SyncCursor $cursor, SyncWindow $window): SyncPage;

    /**
     * @return ProviderAttachmentDefinition[]
     */
    public function listAttachments(Connection $connection, Source $source, ProviderEventReference $event): array;

    public function inspectAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment): AttachmentInspection;

    /**
     * @throws CalendarException DOWNLOAD_TOO_LARGE when the hard limit is exceeded.
     */
    public function downloadAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment, DownloadTarget $target): DownloadResult;
}
