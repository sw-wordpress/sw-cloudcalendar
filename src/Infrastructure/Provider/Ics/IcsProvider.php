<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Provider\Ics;

use SW\Calendar\Application\Http\HttpRequest;
use SW\Calendar\Application\Http\HttpResponse;
use SW\Calendar\Application\Port\CredentialStoreInterface;
use SW\Calendar\Application\Port\HttpClientInterface;
use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\ErrorCode;
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

/**
 * ICS/iCalendar feeds (SPECIFICATIONS.md §6.3). There is no reliable incremental API: every changed feed is a full
 * snapshot, compared locally by UID + RECURRENCE-ID. ETag/Last-Modified avoid re-downloading unchanged feeds.
 *
 * Connection settings: `url` (https; http only with `allow_http`), `max_feed_mb`. Secret: `auth_header` (only for
 * auth mode http_header).
 */
final class IcsProvider implements CalendarProviderInterface
{
    public const DEFAULT_MAX_FEED_MB = 10;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CredentialStoreInterface $credentials,
        private readonly IcsParser $parser = new IcsParser(),
    ) {
    }

    public function capabilities(Connection $connection): ProviderCapabilities
    {
        return new ProviderCapabilities(
            listCalendars: false,
            incrementalSync: false,
            etag: true,
            recurrence: true,
            fileAttachments: true,
            cloudReferenceDownloads: false,
            publicAttachmentVerification: false,
        );
    }

    public function testConnection(Connection $connection): ConnectionTestResult
    {
        try {
            $response = $this->fetch($connection, []);
            $parsed = $this->parser->parse($response->body, new \DateTimeZone('UTC'));
            return new ConnectionTestResult(true, sprintf('Feed loaded: %d events.', count($parsed['items'])), null, $parsed['name']);
        } catch (CalendarException $e) {
            return new ConnectionTestResult(false, $e->getMessage(), $e->errorCode);
        }
    }

    public function listCalendars(Connection $connection): array
    {
        $response = $this->fetch($connection, []);
        $parsed = $this->parser->parse($response->body, new \DateTimeZone('UTC'));
        return [new CalendarDefinition('feed', $parsed['name'] ?? (string) $connection->name, $parsed['timezone'], null, null)];
    }

    public function fetchChanges(Connection $connection, Source $source, SyncCursor $cursor, SyncWindow $window): SyncPage
    {
        $headers = [];
        if (!$cursor->isEmpty()) {
            if ($cursor->get('etag') !== null) {
                $headers['If-None-Match'] = (string) $cursor->get('etag');
            }
            if ($cursor->get('last_modified') !== null) {
                $headers['If-Modified-Since'] = (string) $cursor->get('last_modified');
            }
        }
        $response = $this->fetch($connection, $headers);
        if ($response->status === 304) {
            return new SyncPage([], [], false, null, $cursor, true);
        }
        $parsed = $this->parser->parse($response->body, $source->timeZone());
        $values = array_filter([
            'etag' => $response->header('etag'),
            'last_modified' => $response->header('last-modified'),
            'hash' => hash('sha256', $response->body),
        ], static fn (?string $v): bool => $v !== null && $v !== '');
        return new SyncPage($parsed['items'], [], true, null, new SyncCursor($values));
    }

    public function listAttachments(Connection $connection, Source $source, ProviderEventReference $event): array
    {
        // ATTACH properties are delivered with the events.
        return [];
    }

    public function inspectAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment): AttachmentInspection
    {
        // A freely reachable URL or a public feed is no proof of an intended publication (SPECIFICATIONS.md §6.3).
        return new AttachmentInspection(
            PublicationState::Unknown,
            $attachment->definition->mime,
            $attachment->definition->sizeBytes,
            $attachment->definition->versionToken,
            $attachment->definition->filename,
            'ICS feeds provide no verifiable publication status.',
        );
    }

    public function downloadAttachment(Connection $connection, Source $source, ProviderAttachmentReference $attachment, DownloadTarget $target): DownloadResult
    {
        $definition = $attachment->definition;
        if ($definition->kind === ProviderAttachmentDefinition::KIND_INLINE) {
            $feed = $this->fetch($connection, []);
            $content = $this->parser->inlineAttachment($feed->body, $definition->reference);
            if ($content === null) {
                throw new CalendarException(ErrorCode::FetchFailed, 'The inline attachment is no longer part of the feed.');
            }
            if (strlen($content) > $target->maxBytes) {
                throw new CalendarException(ErrorCode::DownloadTooLarge, 'The attachment exceeds the size limit.');
            }
            if (file_put_contents($target->temporaryPath, $content) === false) {
                throw new CalendarException(ErrorCode::StorageUnsafe, 'The temporary file could not be written.');
            }
            return new DownloadResult(strlen($content), $definition->mime, $definition->versionToken, $definition->filename);
        }
        if ($definition->kind !== ProviderAttachmentDefinition::KIND_URL) {
            throw new CalendarException(ErrorCode::AttachmentUnsupported, 'Unsupported ICS attachment type.');
        }
        $request = (new HttpRequest('GET', $definition->reference, [], null, 60, $target->maxBytes, null, null, (bool) $connection->setting('allow_http', false)))
            ->withSink($target->temporaryPath, $target->maxBytes);
        $response = $this->http->send($request);
        if ($response->status !== 200) {
            throw new CalendarException(ErrorCode::FetchFailed, sprintf('Attachment download failed with HTTP %d.', $response->status));
        }
        return new DownloadResult($response->bytes, $response->header('content-type'), $response->header('etag') ?? $definition->versionToken, $definition->filename);
    }

    /**
     * @param array<string, string> $headers
     */
    private function fetch(Connection $connection, array $headers): HttpResponse
    {
        $url = self::normalizeUrl((string) $connection->setting('url', ''));
        if ($url === '') {
            throw new CalendarException(ErrorCode::FetchFailed, 'No feed URL configured.');
        }
        $headers['Accept'] = 'text/calendar, application/calendar+json;q=0.1, */*;q=0.1';
        if ($connection->authMode === AuthMode::HttpHeader) {
            $secret = $this->credentials->get($connection->id)['auth_header'] ?? '';
            if ($secret === '') {
                throw new CalendarException(ErrorCode::AuthRequired, 'The authorization header of this feed is missing.');
            }
            $headers['Authorization'] = $secret;
        }
        $maxMb = max(1, min(50, (int) $connection->setting('max_feed_mb', self::DEFAULT_MAX_FEED_MB)));
        $response = $this->http->send(new HttpRequest(
            'GET',
            $url,
            $headers,
            null,
            30,
            $maxMb * 1024 * 1024,
            null,
            null,
            (bool) $connection->setting('allow_http', false),
        ));
        if ($response->status === 304) {
            return $response;
        }
        if ($response->status === 401 || $response->status === 403) {
            throw new CalendarException(ErrorCode::AuthRequired, sprintf('The feed refused access (HTTP %d).', $response->status));
        }
        if ($response->status === 429 || $response->status === 503) {
            throw new CalendarException(ErrorCode::RateLimited, sprintf('The feed server is busy (HTTP %d).', $response->status), $response->retryAfterSeconds());
        }
        if ($response->status !== 200) {
            throw new CalendarException(ErrorCode::FetchFailed, sprintf('The feed could not be loaded (HTTP %d).', $response->status));
        }
        return $response;
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (stripos($url, 'webcal://') === 0) {
            $url = 'https://' . substr($url, 9);
        } elseif (stripos($url, 'webcals://') === 0) {
            $url = 'https://' . substr($url, 10);
        }
        return $url;
    }
}
