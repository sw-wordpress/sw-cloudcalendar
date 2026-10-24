<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Support;

use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\Connection;
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

/**
 * Provider fake returning a scripted sequence of pages or exceptions and recording the cursors it was called with.
 */
final class ScriptedProvider implements CalendarProviderInterface
{
    /** @var list<SyncPage|CalendarException|\Closure> */
    public array $script = [];
    /** @var list<SyncCursor> */
    public array $calls = [];

    public function capabilities(Connection $connection): ProviderCapabilities
    {
        return new ProviderCapabilities(false, true, false, true, false, false, false);
    }

    public function testConnection(Connection $connection): ConnectionTestResult
    {
        return new ConnectionTestResult(true, 'ok');
    }

    public function listCalendars(Connection $connection): array
    {
        return [];
    }

    public function fetchChanges(Connection $connection, Source $source, SyncCursor $cursor, SyncWindow $window): SyncPage
    {
        $this->calls[] = $cursor;
        $next = array_shift($this->script);
        if ($next instanceof \Closure) {
            $next = $next($cursor, $window);
        }
        if ($next instanceof CalendarException) {
            throw $next;
        }
        if ($next === null) {
            throw new \LogicException('Script exhausted');
        }
        return $next;
    }

    public function listAttachments(Connection $connection, Source $source, ProviderEventReference $event): array
    {
        return [];
    }

    public function inspectAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment): AttachmentInspection
    {
        throw new \LogicException('not scripted');
    }

    public function downloadAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment, DownloadTarget $target): DownloadResult
    {
        throw new \LogicException('not scripted');
    }
}
