<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Provider\Google;

use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Event\ProviderEvent;
use SW\Calendar\Domain\Provider\AttachmentInspection;
use SW\Calendar\Domain\Provider\CalendarDefinition;
use SW\Calendar\Domain\Provider\CalendarProviderInterface;
use SW\Calendar\Domain\Provider\ConnectionTestResult;
use SW\Calendar\Domain\Provider\DownloadResult;
use SW\Calendar\Domain\Provider\DownloadTarget;
use SW\Calendar\Domain\Provider\ProviderAttachmentDefinition;
use SW\Calendar\Domain\Provider\ProviderAttachmentReference;
use SW\Calendar\Domain\Provider\ProviderCapabilities;
use SW\Calendar\Domain\Provider\ProviderEventReference;
use SW\Calendar\Domain\Provider\SyncCursor;
use SW\Calendar\Domain\Provider\SyncPage;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Infrastructure\Provider\AuthorizedApi;

/**
 * Google Calendar API v3 (SPECIFICATIONS.md §6.1).
 *
 * Reference strategy: `events.list` WITHOUT time filters and with `singleEvents=false`, so the sync token stays valid
 * independent of the local window; recurring masters are expanded locally. `showDeleted=true` in full and delta runs
 * (deleted entries are always part of delta results). `410 Gone` -> SYNC_CURSOR_INVALID -> full rebuild.
 * Past one-off events outside the window are not kept (they can never become visible again; a larger past window
 * forces a full sync).
 *
 * Attachments: Drive files via the Drive API (`attachment_access` connection setting, scope drive.readonly). Public
 * status is PUBLIC_VERIFIED only with an `anyone` reader permission; if permissions cannot be read it is UNKNOWN.
 */
final class GoogleCalendarProvider implements CalendarProviderInterface
{
    private const CALENDAR_API = 'https://www.googleapis.com/calendar/v3';
    private const DRIVE_API = 'https://www.googleapis.com/drive/v3';
    private const PAGE_SIZE = 250;
    private const EXPORT_FORMATS = ['application/pdf'];

    public function __construct(private readonly AuthorizedApi $api)
    {
    }

    public function capabilities(Connection $connection): ProviderCapabilities
    {
        $oauth = $connection->authMode === AuthMode::OAuth;
        $files = $oauth && (bool) $connection->setting('attachment_access', false);
        return new ProviderCapabilities(
            listCalendars: $oauth,
            incrementalSync: true,
            etag: false,
            recurrence: true,
            fileAttachments: $files,
            cloudReferenceDownloads: $files,
            publicAttachmentVerification: $files,
        );
    }

    public function testConnection(Connection $connection): ConnectionTestResult
    {
        try {
            if ($connection->authMode === AuthMode::ApiKey) {
                $calendarId = (string) $connection->setting('test_calendar_id', '');
                if ($calendarId === '') {
                    return new ConnectionTestResult(true, 'API key stored. It is verified with the first source.');
                }
                $data = $this->api->json($connection, self::CALENDAR_API . '/calendars/' . rawurlencode($calendarId));
                return new ConnectionTestResult(true, 'Calendar reachable: ' . (string) ($data['summary'] ?? $calendarId));
            }
            $data = $this->api->json($connection, self::CALENDAR_API . '/users/me/calendarList?maxResults=1&fields=items(id)');
            $primary = $this->api->json($connection, self::CALENDAR_API . '/calendars/primary?fields=id');
            return new ConnectionTestResult(true, 'Connected.', null, isset($primary['id']) ? (string) $primary['id'] : null);
        } catch (CalendarException $e) {
            return new ConnectionTestResult(false, $e->getMessage(), $e->errorCode);
        }
    }

    public function listCalendars(Connection $connection): array
    {
        if ($connection->authMode !== AuthMode::OAuth) {
            throw new CalendarException(ErrorCode::ProviderUnsupported, 'Calendars cannot be listed with an API key; enter the calendar ID.');
        }
        $result = [];
        $pageToken = null;
        do {
            $url = self::CALENDAR_API . '/users/me/calendarList?maxResults=250&fields=nextPageToken,items(id,summary,timeZone,backgroundColor,accessRole)'
                . ($pageToken !== null ? '&pageToken=' . rawurlencode($pageToken) : '');
            $data = $this->api->json($connection, $url);
            foreach ((array) ($data['items'] ?? []) as $item) {
                if (!is_array($item) || !isset($item['id'])) {
                    continue;
                }
                $result[] = new CalendarDefinition(
                    (string) $item['id'],
                    (string) ($item['summary'] ?? $item['id']),
                    isset($item['timeZone']) ? (string) $item['timeZone'] : null,
                    isset($item['backgroundColor']) ? strtolower((string) $item['backgroundColor']) : null,
                    // The ACL of the Google calendar is not inspected: WordPress publication is a separate decision.
                    null,
                );
            }
            $pageToken = isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : null;
        } while ($pageToken !== null);
        return $result;
    }

    public function fetchChanges(Connection $connection, Source $source, SyncCursor $cursor, SyncWindow $window): SyncPage
    {
        $syncToken = $cursor->get('sync_token');
        $pageToken = $cursor->get('page_token');
        $delta = $syncToken !== null && $syncToken !== '';
        $params = [
            'singleEvents' => 'false',
            'showDeleted' => 'true',
            'maxResults' => (string) self::PAGE_SIZE,
        ];
        if ($delta) {
            $params['syncToken'] = $syncToken;
        }
        if ($pageToken !== null && $pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }
        $data = $this->api->json($connection, self::CALENDAR_API . '/calendars/' . rawurlencode($source->externalId) . '/events?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $calendarTz = isset($data['timeZone']) ? (string) $data['timeZone'] : $source->timezone;

        $items = [];
        $removed = [];
        foreach ((array) ($data['items'] ?? []) as $raw) {
            if (!is_array($raw) || !isset($raw['id'])) {
                continue;
            }
            $event = $this->normalize($raw, $calendarTz, $source->timezone);
            if ($event === null) {
                // Cancelled one-off event or master: remove it.
                $removed[] = (string) $raw['id'];
                continue;
            }
            if (!$event->isRecurringMaster() && $event->recurrenceId === null && $event->end < $window->start) {
                $removed[] = $event->itemId;
                continue;
            }
            $items[] = $event;
        }

        $nextPage = isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : null;
        if ($nextPage !== null) {
            $values = ['page_token' => $nextPage];
            if ($delta) {
                $values['sync_token'] = (string) $syncToken;
            }
            return new SyncPage($items, $removed, !$delta, new SyncCursor($values), null);
        }
        if (!isset($data['nextSyncToken'])) {
            throw new CalendarException(ErrorCode::FetchFailed, 'The last page did not contain a sync token.');
        }
        return new SyncPage($items, $removed, !$delta, null, new SyncCursor(['sync_token' => (string) $data['nextSyncToken']]));
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function normalize(array $raw, string $calendarTz, string $fallbackTz): ?ProviderEvent
    {
        $status = OccurrenceStatus::fromProvider(isset($raw['status']) ? (string) $raw['status'] : null);
        $isOverride = isset($raw['recurringEventId']);
        if ($status === OccurrenceStatus::Cancelled && !$isOverride) {
            return null;
        }
        $tzName = self::validTz((string) ($raw['start']['timeZone'] ?? '')) ?? self::validTz($calendarTz) ?? self::validTz($fallbackTz) ?? 'UTC';
        $original = is_array($raw['originalStartTime'] ?? null) ? $raw['originalStartTime'] : null;
        $startData = is_array($raw['start'] ?? null) ? $raw['start'] : $original;
        $endData = is_array($raw['end'] ?? null) ? $raw['end'] : $startData;
        if ($startData === null || $endData === null) {
            return null;
        }
        $allDay = isset($startData['date']);
        [$start] = self::time($startData, $tzName);
        [$end] = self::time($endData, $tzName);
        if ($end < $start) {
            $end = $start;
        }

        $recurrenceId = null;
        if ($isOverride && $original !== null) {
            [$originalStart, $originalAllDay] = self::time($original, $tzName);
            $recurrenceId = $originalAllDay ? $originalStart->format('Y-m-d') : $originalStart->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        [$rrule, $exdates, $rdates] = $this->recurrence((array) ($raw['recurrence'] ?? []), $tzName, $allDay);
        $attachments = [];
        foreach ((array) ($raw['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $fileId = (string) ($attachment['fileId'] ?? '');
            $attachments[] = new ProviderAttachmentDefinition(
                $fileId !== '' ? ProviderAttachmentDefinition::KIND_CLOUD : ProviderAttachmentDefinition::KIND_URL,
                $fileId !== '' ? $fileId : (string) ($attachment['fileUrl'] ?? ''),
                (string) ($attachment['title'] ?? 'attachment'),
                strtolower((string) ($attachment['mimeType'] ?? 'application/octet-stream')),
            );
        }
        $description = (string) ($raw['description'] ?? '');
        $visibility = (string) ($raw['visibility'] ?? 'default');

        return new ProviderEvent(
            (string) $raw['id'],
            $isOverride ? (string) $raw['recurringEventId'] : null,
            $recurrenceId,
            (string) ($raw['summary'] ?? ''),
            // Google descriptions may contain HTML; it is sanitized by the occurrence builder.
            $description,
            '',
            (string) ($raw['location'] ?? ''),
            (string) ($raw['htmlLink'] ?? ''),
            $start,
            $end,
            $allDay,
            $allDay ? 'UTC' : $tzName,
            $status,
            $rrule,
            $exdates,
            $rdates,
            isset($raw['eventType']) ? (string) $raw['eventType'] : 'default',
            $visibility === 'private' || $visibility === 'confidential',
            $attachments,
            isset($raw['updated']) ? new \DateTimeImmutable((string) $raw['updated']) : null,
        );
    }

    public function listAttachments(Connection $connection, Source $source, ProviderEventReference $event): array
    {
        // Attachment metadata is part of the event resource.
        return [];
    }

    public function inspectAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment): AttachmentInspection
    {
        $definition = $attachment->definition;
        if ($definition->kind !== ProviderAttachmentDefinition::KIND_CLOUD || !$this->capabilities($connection)->fileAttachments) {
            return new AttachmentInspection(PublicationState::Unsupported, null, null, null, null, 'No Drive access configured or no Drive file.');
        }
        $file = $this->api->json($connection, self::DRIVE_API . '/files/' . rawurlencode($definition->reference) . '?supportsAllDrives=true&fields=id,name,mimeType,size,version,modifiedTime');
        $mime = strtolower((string) ($file['mimeType'] ?? $definition->mime));
        $state = PublicationState::Unknown;
        $reason = null;
        try {
            $permissions = $this->api->json($connection, self::DRIVE_API . '/files/' . rawurlencode($definition->reference) . '/permissions?supportsAllDrives=true&fields=permissions(type,role)');
            $state = PublicationState::Private;
            foreach ((array) ($permissions['permissions'] ?? []) as $permission) {
                if (is_array($permission) && ($permission['type'] ?? '') === 'anyone' && in_array($permission['role'] ?? '', ['reader', 'commenter', 'writer', 'fileOrganizer', 'organizer', 'owner'], true)) {
                    $state = PublicationState::PublicVerified;
                }
            }
        } catch (CalendarException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            // Only users allowed to share a file may read its permissions: without them the status stays unknown.
            $reason = 'Drive permissions not readable.';
        }
        $native = str_starts_with($mime, 'application/vnd.google-apps.');
        return new AttachmentInspection(
            $state,
            $native ? self::EXPORT_FORMATS[0] : $mime,
            $native || !isset($file['size']) ? null : (int) $file['size'],
            (string) ($file['version'] ?? $file['modifiedTime'] ?? ''),
            (string) ($file['name'] ?? $definition->filename),
            $reason,
        );
    }

    public function downloadAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment, DownloadTarget $target): DownloadResult
    {
        $definition = $attachment->definition;
        if ($definition->kind !== ProviderAttachmentDefinition::KIND_CLOUD) {
            throw new CalendarException(ErrorCode::AttachmentUnsupported, 'Only Google Drive attachments can be downloaded.');
        }
        $file = $this->api->json($connection, self::DRIVE_API . '/files/' . rawurlencode($definition->reference) . '?supportsAllDrives=true&fields=mimeType,name,version');
        $mime = (string) ($file['mimeType'] ?? '');
        $url = str_starts_with($mime, 'application/vnd.google-apps.')
            ? self::DRIVE_API . '/files/' . rawurlencode($definition->reference) . '/export?mimeType=' . rawurlencode(self::EXPORT_FORMATS[0])
            : self::DRIVE_API . '/files/' . rawurlencode($definition->reference) . '?alt=media&supportsAllDrives=true';
        $response = $this->api->get($connection, $url, ['Accept' => '*/*'], $target->temporaryPath, $target->maxBytes);
        $this->api->assertOk($response);
        return new DownloadResult($response->bytes, $response->header('content-type'), isset($file['version']) ? (string) $file['version'] : null, isset($file['name']) ? (string) $file['name'] : null);
    }

    /**
     * Parses RRULE/EXDATE/RDATE lines of the `recurrence` array.
     *
     * @param array<mixed> $lines
     * @return array{0: ?string, 1: list<\DateTimeImmutable>, 2: list<\DateTimeImmutable>}
     */
    private function recurrence(array $lines, string $tzName, bool $allDay): array
    {
        $rrule = null;
        $exdates = [];
        $rdates = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $head = strtoupper(substr($line, 0, $colon));
            $value = substr($line, $colon + 1);
            $parts = explode(';', $head);
            $name = array_shift($parts);
            if ($name === 'RRULE') {
                $rrule = $value;
                continue;
            }
            if ($name !== 'EXDATE' && $name !== 'RDATE') {
                continue;
            }
            $tz = $tzName;
            foreach (explode(';', substr($line, 0, $colon)) as $param) {
                if (stripos($param, 'TZID=') === 0) {
                    $tz = self::validTz(substr($param, 5)) ?? $tzName;
                }
            }
            foreach (explode(',', $value) as $raw) {
                $date = self::parseIcalDate(trim($raw), $tz, $allDay);
                if ($date === null) {
                    continue;
                }
                if ($name === 'EXDATE') {
                    $exdates[] = $date;
                } else {
                    $rdates[] = $date;
                }
            }
        }
        return [$rrule, $exdates, $rdates];
    }

    private static function parseIcalDate(string $value, string $tz, bool $allDay): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/', $value, $m) !== 1) {
            return null;
        }
        if ($allDay || !isset($m[4]) || $m[4] === '') {
            return new \DateTimeImmutable($m[1] . '-' . $m[2] . '-' . $m[3] . ' 00:00:00', new \DateTimeZone('UTC'));
        }
        $zone = new \DateTimeZone(($m[7] ?? '') === 'Z' ? 'UTC' : $tz);
        return new \DateTimeImmutable(sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5] ?? '00', $m[6] ?? '00'), $zone);
    }

    /**
     * @param array<string, mixed> $data `{date}` or `{dateTime, timeZone}`
     * @return array{0: \DateTimeImmutable, 1: bool}
     */
    private static function time(array $data, string $tzName): array
    {
        if (isset($data['date'])) {
            return [new \DateTimeImmutable((string) $data['date'] . ' 00:00:00', new \DateTimeZone('UTC')), true];
        }
        $tz = new \DateTimeZone(self::validTz((string) ($data['timeZone'] ?? '')) ?? $tzName);
        return [(new \DateTimeImmutable((string) ($data['dateTime'] ?? 'now')))->setTimezone($tz), false];
    }

    private static function validTz(string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        try {
            new \DateTimeZone($name);
            return $name;
        } catch (\Exception) {
            return null;
        }
    }
}
