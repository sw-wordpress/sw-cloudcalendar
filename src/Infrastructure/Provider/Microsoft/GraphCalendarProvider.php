<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Provider\Microsoft;

use Sabre\VObject\TimeZoneUtil;
use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\CalendarException;
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
 * Microsoft Graph v1.0 with delegated permissions of ONE signed-in account (SPECIFICATIONS.md §6.2).
 *
 * Events: `/me/calendars/{id}/calendarView/delta` (instances, exceptions and single events of the window; no local
 * expansion). `@odata.nextLink`/`@odata.deltaLink` are stored and used unchanged. The delta track is bound to the
 * window: the cursor carries the window and the sync service starts a new track (full reconciliation) when it moves.
 *
 * Attachments: `fileAttachment` only (raw content via `/$value`). Files of a mailbox are never public, so
 * `public_only` sources skip them (PRIVATE). `referenceAttachment` (OneDrive/SharePoint links) and `itemAttachment`
 * are UNSUPPORTED in V1: their sharing state is not verified by this adapter (docs/decisions.md D-17).
 * Shared mailboxes, group calendars and app-only access are not supported in V1.
 */
final class GraphCalendarProvider implements CalendarProviderInterface
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const PAGE_SIZE = 100;

    public function __construct(private readonly AuthorizedApi $api)
    {
    }

    public function capabilities(Connection $connection): ProviderCapabilities
    {
        return new ProviderCapabilities(
            listCalendars: true,
            incrementalSync: true,
            etag: false,
            recurrence: false,
            fileAttachments: true,
            cloudReferenceDownloads: false,
            publicAttachmentVerification: false,
        );
    }

    public function testConnection(Connection $connection): ConnectionTestResult
    {
        try {
            $me = $this->api->json($connection, self::GRAPH . '/me?$select=id,userPrincipalName');
            return new ConnectionTestResult(true, 'Connected.', null, (string) ($me['userPrincipalName'] ?? $me['id'] ?? ''));
        } catch (CalendarException $e) {
            return new ConnectionTestResult(false, $e->getMessage(), $e->errorCode);
        }
    }

    public function listCalendars(Connection $connection): array
    {
        $result = [];
        $url = self::GRAPH . '/me/calendars?$select=id,name,hexColor,canEdit,owner&$top=100';
        for ($guard = 0; $url !== null && $guard < 50; $guard++) {
            $data = $this->api->json($connection, $url);
            foreach ((array) ($data['value'] ?? []) as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $color = (string) ($item['hexColor'] ?? '');
                    $result[] = new CalendarDefinition((string) $item['id'], (string) ($item['name'] ?? $item['id']), null, preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : null, null);
                }
            }
            $url = isset($data['@odata.nextLink']) ? (string) $data['@odata.nextLink'] : null;
        }
        return $result;
    }

    public function fetchChanges(Connection $connection, Source $source, SyncCursor $cursor, SyncWindow $window): SyncPage
    {
        $url = $cursor->get('next_link') ?? $cursor->get('delta_link');
        $delta = $cursor->get('delta_link') !== null || $cursor->get('mode') === 'delta';
        if ($url === null) {
            $url = self::GRAPH . '/me/calendars/' . rawurlencode($source->externalId) . '/calendarView/delta?' . http_build_query([
                'startDateTime' => $window->start->format('Y-m-d\TH:i:s\Z'),
                'endDateTime' => $window->end->format('Y-m-d\TH:i:s\Z'),
            ], '', '&', PHP_QUERY_RFC3986);
            $delta = false;
        }
        if (!str_starts_with($url, self::GRAPH . '/')) {
            throw new CalendarException(ErrorCode::SyncCursorInvalid, 'Unexpected continuation link.');
        }
        $data = $this->api->json($connection, $url, [
            'Prefer' => 'outlook.timezone="UTC", odata.maxpagesize=' . self::PAGE_SIZE . ', outlook.body-content-type="html"',
        ]);

        $items = [];
        $removed = [];
        $withAttachments = $source->settings->attachments->syncAttachments;
        foreach ((array) ($data['value'] ?? []) as $raw) {
            if (!is_array($raw) || !isset($raw['id'])) {
                continue;
            }
            if (isset($raw['@removed'])) {
                $removed[] = (string) $raw['id'];
                continue;
            }
            $event = $this->normalize($raw, $source->timezone);
            if ($event === null) {
                $removed[] = (string) $raw['id'];
                continue;
            }
            if ($withAttachments && !empty($raw['hasAttachments'])) {
                $event = $this->withAttachments($connection, $event);
            }
            $items[] = $event;
        }

        $mode = $delta ? 'delta' : 'full';
        if (isset($data['@odata.nextLink'])) {
            return new SyncPage($items, $removed, !$delta, new SyncCursor(['next_link' => (string) $data['@odata.nextLink'], 'mode' => $mode], $window), null);
        }
        if (!isset($data['@odata.deltaLink'])) {
            throw new CalendarException(ErrorCode::FetchFailed, 'The last page did not contain a delta link.');
        }
        return new SyncPage($items, $removed, !$delta, null, new SyncCursor(['delta_link' => (string) $data['@odata.deltaLink']], $window));
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function normalize(array $raw, string $fallbackTz): ?ProviderEvent
    {
        if (!empty($raw['isCancelled'])) {
            return null;
        }
        if (!is_array($raw['start'] ?? null) || !is_array($raw['end'] ?? null)) {
            return null;
        }
        $allDay = !empty($raw['isAllDay']);
        $start = self::time($raw['start'], $allDay);
        $end = self::time($raw['end'], $allDay);
        if ($end < $start) {
            $end = $start;
        }
        $tz = self::ianaTz((string) ($raw['originalStartTimeZone'] ?? '')) ?? $fallbackTz;
        if (!$allDay) {
            try {
                $start = $start->setTimezone(new \DateTimeZone($tz));
                $end = $end->setTimezone(new \DateTimeZone($tz));
            } catch (\Exception) {
                $tz = 'UTC';
            }
        }
        $seriesId = isset($raw['seriesMasterId']) && $raw['seriesMasterId'] !== '' ? (string) $raw['seriesMasterId'] : null;
        $recurrenceId = null;
        if ($seriesId !== null && isset($raw['originalStart'])) {
            $original = self::time(['dateTime' => (string) $raw['originalStart'], 'timeZone' => 'UTC'], $allDay);
            $recurrenceId = $allDay ? $original->format('Y-m-d') : $original->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        $body = is_array($raw['body'] ?? null) ? $raw['body'] : [];
        $isHtml = strtolower((string) ($body['contentType'] ?? 'text')) === 'html';
        $content = (string) ($body['content'] ?? '');
        $showAs = strtolower((string) ($raw['showAs'] ?? ''));
        $sensitivity = strtolower((string) ($raw['sensitivity'] ?? 'normal'));

        return new ProviderEvent(
            (string) $raw['id'],
            $seriesId,
            $recurrenceId,
            (string) ($raw['subject'] ?? ''),
            $isHtml ? $content : '',
            $isHtml ? '' : $content,
            (string) (is_array($raw['location'] ?? null) ? ($raw['location']['displayName'] ?? '') : ''),
            (string) ($raw['webLink'] ?? ''),
            $start,
            $end,
            $allDay,
            $allDay ? 'UTC' : $tz,
            $showAs === 'tentative' ? OccurrenceStatus::Tentative : OccurrenceStatus::Confirmed,
            null,
            [],
            [],
            null,
            // `sensitivity` is NOT a WordPress ACL; it only feeds the optional "exclude private events" filter.
            $sensitivity === 'private' || $sensitivity === 'confidential',
            [],
            isset($raw['lastModifiedDateTime']) ? new \DateTimeImmutable((string) $raw['lastModifiedDateTime']) : null,
            true,
        );
    }

    public function listAttachments(Connection $connection, Source $source, ProviderEventReference $event): array
    {
        return $this->attachmentDefinitions($connection, $event->providerEventId);
    }

    public function inspectAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment): AttachmentInspection
    {
        $definition = $attachment->definition;
        if ($definition->kind !== ProviderAttachmentDefinition::KIND_FILE) {
            return new AttachmentInspection(PublicationState::Unsupported, null, null, null, null, 'Reference and item attachments are not supported.');
        }
        // A file attached to a mailbox item is private by nature; there is no public sharing state to verify.
        return new AttachmentInspection(PublicationState::Private, $definition->mime, $definition->sizeBytes, $definition->versionToken, $definition->filename, 'Mailbox file attachments are private.');
    }

    public function downloadAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment, DownloadTarget $target): DownloadResult
    {
        $definition = $attachment->definition;
        if ($definition->kind !== ProviderAttachmentDefinition::KIND_FILE) {
            throw new CalendarException(ErrorCode::AttachmentUnsupported, 'Only file attachments can be downloaded.');
        }
        $url = self::GRAPH . '/me/events/' . rawurlencode($attachment->providerEventId) . '/attachments/' . rawurlencode($definition->reference) . '/$value';
        $response = $this->api->get($connection, $url, ['Accept' => '*/*'], $target->temporaryPath, $target->maxBytes);
        $this->api->assertOk($response);
        return new DownloadResult($response->bytes, $response->header('content-type'), $definition->versionToken, $definition->filename);
    }

    private function withAttachments(Connection $connection, ProviderEvent $event): ProviderEvent
    {
        try {
            $definitions = $this->attachmentDefinitions($connection, $event->itemId);
        } catch (CalendarException $e) {
            if ($e->isRetryable()) {
                throw $e;
            }
            return $event;
        }
        $data = $event->toArray();
        $data['attachments'] = array_map(static fn (ProviderAttachmentDefinition $d): array => $d->toArray(), $definitions);
        return ProviderEvent::fromArray($data);
    }

    /**
     * Non-inline attachments of an event (inline images of the HTML body are not listed).
     *
     * @return list<ProviderAttachmentDefinition>
     */
    private function attachmentDefinitions(Connection $connection, string $eventId): array
    {
        $data = $this->api->json($connection, self::GRAPH . '/me/events/' . rawurlencode($eventId) . '/attachments?$select=id,name,contentType,size,lastModifiedDateTime,isInline');
        $result = [];
        foreach ((array) ($data['value'] ?? []) as $raw) {
            if (!is_array($raw) || !isset($raw['id']) || !empty($raw['isInline'])) {
                continue;
            }
            $result[] = new ProviderAttachmentDefinition(
                match ((string) ($raw['@odata.type'] ?? '')) {
                    '#microsoft.graph.fileAttachment' => ProviderAttachmentDefinition::KIND_FILE,
                    '#microsoft.graph.referenceAttachment' => ProviderAttachmentDefinition::KIND_CLOUD,
                    default => ProviderAttachmentDefinition::KIND_ITEM,
                },
                (string) $raw['id'],
                (string) ($raw['name'] ?? 'attachment'),
                strtolower((string) ($raw['contentType'] ?? 'application/octet-stream')),
                isset($raw['size']) ? (int) $raw['size'] : null,
                isset($raw['lastModifiedDateTime']) ? (string) $raw['lastModifiedDateTime'] : null,
            );
        }
        return $result;
    }

    /**
     * Graph returns `{dateTime, timeZone}`; with the UTC preference the zone is UTC. All-day events are midnight
     * dates; a value shifted by a time zone conversion is rounded to the nearest date (docs/decisions.md D-16).
     *
     * @param array<string, mixed> $data
     */
    private static function time(array $data, bool $allDay): \DateTimeImmutable
    {
        $zoneName = (string) ($data['timeZone'] ?? 'UTC');
        $zone = self::ianaTz($zoneName) ?? 'UTC';
        $value = (string) ($data['dateTime'] ?? 'now');
        // Graph uses 7 fractional digits, which PHP does not parse in every version.
        $value = (string) preg_replace('/(\.\d{6})\d+/', '$1', $value);
        $time = new \DateTimeImmutable($value, new \DateTimeZone($zone));
        if (!$allDay) {
            return $time;
        }
        $date = $time->setTime(0, 0);
        if ((int) $time->format('H') >= 12) {
            $date = $date->modify('+1 day');
        }
        return new \DateTimeImmutable($date->format('Y-m-d') . ' 00:00:00', new \DateTimeZone('UTC'));
    }

    /** Maps Windows and IANA zone names (Graph uses Windows names such as "W. Europe Standard Time"). */
    private static function ianaTz(string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        try {
            return (new \DateTimeZone($name))->getName();
        } catch (\Exception) {
            try {
                $resolved = TimeZoneUtil::getTimeZone($name)->getName();
            } catch (\Throwable) {
                return null;
            }
            // Unknown names fall back to the default zone (UTC in WordPress): treat that as "not resolvable".
            return $resolved === 'UTC' && preg_match('/UTC|GMT|Coordinated Universal/i', $name) !== 1 ? null : $resolved;
        }
    }
}
