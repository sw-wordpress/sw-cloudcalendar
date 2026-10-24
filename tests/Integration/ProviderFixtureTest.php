<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Event\OccurrenceStatus;
use SW\Calendar\Domain\Provider\SyncCursor;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Infrastructure\OAuth\OAuthEndpoints;
use SW\Calendar\Infrastructure\OAuth\OAuthService;
use SW\Calendar\Infrastructure\Provider\AuthorizedApi;
use SW\Calendar\Infrastructure\Provider\Google\GoogleCalendarProvider;
use SW\Calendar\Infrastructure\Provider\Microsoft\GraphCalendarProvider;
use SW\Calendar\Support\FrozenClock;
use SW\Calendar\Tests\Support\FakeHttpClient;
use SW\Calendar\Tests\Support\InMemoryCredentials;
use SW\Calendar\Tests\Support\TestData;

final class ProviderFixtureTest extends TestCase
{
    private FakeHttpClient $http;
    private InMemoryCredentials $credentials;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->credentials = new InMemoryCredentials();
        $this->clock = new FrozenClock('2026-10-24T12:00:00Z');
        $this->credentials->secrets[1] = ['access_token' => 'tok-1', 'refresh_token' => 'ref-1', 'expires_at' => (string) ($this->clock->now()->getTimestamp() + 3600), 'client_secret' => 's'];
    }

    private function connection(Provider $provider): Connection
    {
        return new Connection(1, $provider, 'c', AuthMode::OAuth, ConnectionStatus::Connected, null, ['client_id' => 'cid', 'tenant_id' => 'contoso.onmicrosoft.com']);
    }

    private function api(Provider $provider): AuthorizedApi
    {
        $hosts = $provider === Provider::Google ? OAuthEndpoints::GOOGLE_HOSTS : OAuthEndpoints::MICROSOFT_HOSTS;
        return new AuthorizedApi($this->http, new OAuthService($this->http, $this->credentials, $this->clock), $this->credentials, $hosts);
    }

    private function window(): SyncWindow
    {
        return SyncWindow::around($this->clock->now(), 90, 365);
    }

    public function testGoogleFullSyncPagesWithoutTimeFiltersAndDeltaWithToken(): void
    {
        $provider = new GoogleCalendarProvider($this->api(Provider::Google));
        $source = TestData::source(1);
        $this->http->push(200, ['timeZone' => 'Europe/Berlin', 'nextPageToken' => 'p2', 'items' => [
            ['id' => 'm1', 'status' => 'confirmed', 'summary' => 'Weekly', 'start' => ['dateTime' => '2026-11-02T09:00:00+01:00', 'timeZone' => 'Europe/Berlin'], 'end' => ['dateTime' => '2026-11-02T10:00:00+01:00'], 'recurrence' => ['RRULE:FREQ=WEEKLY;COUNT=5', 'EXDATE;TZID=Europe/Berlin:20261109T090000']],
            ['id' => 'old', 'status' => 'confirmed', 'summary' => 'Old', 'start' => ['dateTime' => '2020-01-01T09:00:00Z'], 'end' => ['dateTime' => '2020-01-01T10:00:00Z']],
        ]]);
        $page1 = $provider->fetchChanges($this->connection(Provider::Google), $source, SyncCursor::empty(), $this->window());
        self::assertStringNotContainsString('timeMin', $this->http->requests[0]->url);
        self::assertStringNotContainsString('timeMax', $this->http->requests[0]->url);
        self::assertStringContainsString('singleEvents=false', $this->http->requests[0]->url);
        self::assertSame('Bearer tok-1', $this->http->requests[0]->headers['Authorization']);
        self::assertTrue($page1->fullSnapshot);
        self::assertCount(1, $page1->items);
        self::assertSame(['old'], $page1->removedItemIds, 'Past one-off events are not kept');
        self::assertSame('FREQ=WEEKLY;COUNT=5', $page1->items[0]->rrule);
        self::assertSame('2026-11-09 08:00', $page1->items[0]->exdates[0]->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));

        $this->http->push(200, ['items' => [
            ['id' => 'm1_20261116T080000Z', 'status' => 'cancelled', 'recurringEventId' => 'm1', 'originalStartTime' => ['dateTime' => '2026-11-16T09:00:00+01:00']],
        ], 'nextSyncToken' => 'sync-1']);
        $page2 = $provider->fetchChanges($this->connection(Provider::Google), $source, $page1->nextPage, $this->window());
        self::assertStringContainsString('pageToken=p2', $this->http->requests[1]->url);
        self::assertSame('sync-1', $page2->finalCursor->get('sync_token'));
        self::assertSame(OccurrenceStatus::Cancelled, $page2->items[0]->status);
        self::assertSame('2026-11-16T08:00:00Z', $page2->items[0]->recurrenceId);

        $this->http->push(200, ['items' => [['id' => 'gone', 'status' => 'cancelled']], 'nextSyncToken' => 'sync-2']);
        $delta = $provider->fetchChanges($this->connection(Provider::Google), $source, $page2->finalCursor, $this->window());
        self::assertStringContainsString('syncToken=sync-1', $this->http->requests[2]->url);
        self::assertStringNotContainsString('timeMin', $this->http->requests[2]->url);
        self::assertFalse($delta->fullSnapshot);
        self::assertSame(['gone'], $delta->removedItemIds);
    }

    public function testGoogleGoneMapsToCursorInvalidAnd401RefreshesOnce(): void
    {
        $provider = new GoogleCalendarProvider($this->api(Provider::Google));
        $this->http->push(401, ['error' => ['code' => 401]]);
        $this->http->push(200, ['access_token' => 'tok-2', 'expires_in' => 3600]);
        $this->http->push(410, ['error' => ['code' => 410, 'errors' => [['reason' => 'fullSyncRequired']]]]);
        try {
            $provider->fetchChanges($this->connection(Provider::Google), TestData::source(1), new SyncCursor(['sync_token' => 'x']), $this->window());
            self::fail('expected exception');
        } catch (CalendarException $e) {
            self::assertSame(ErrorCode::SyncCursorInvalid, $e->errorCode);
        }
        self::assertSame('tok-2', $this->credentials->secrets[1]['access_token']);
        self::assertSame('ref-1', $this->credentials->secrets[1]['refresh_token'], 'Refresh token kept when not rotated');
        self::assertSame('Bearer tok-2', $this->http->requests[2]->headers['Authorization']);
    }

    public function testGraphDeltaUsesOpaqueLinksAndBindsWindow(): void
    {
        $provider = new GraphCalendarProvider($this->api(Provider::Microsoft));
        $source = TestData::source(1, settings: new SourceSettings());
        $next = 'https://graph.microsoft.com/v1.0/me/calendars/ext-1/calendarView/delta?$skiptoken=OPAQUE%2Bvalue';
        $this->http->push(200, ['@odata.nextLink' => $next, 'value' => [
            ['id' => 'e1', 'subject' => 'Single', 'start' => ['dateTime' => '2026-11-03T08:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-11-03T09:00:00.0000000', 'timeZone' => 'UTC'], 'originalStartTimeZone' => 'W. Europe Standard Time', 'showAs' => 'tentative', 'sensitivity' => 'private', 'body' => ['contentType' => 'html', 'content' => '<p>x</p>']],
            ['id' => 'e2', 'isAllDay' => true, 'subject' => 'Day', 'start' => ['dateTime' => '2026-12-23T23:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-12-24T23:00:00.0000000', 'timeZone' => 'UTC'], 'seriesMasterId' => 'm', 'originalStart' => '2026-12-23T23:00:00Z'],
        ]]);
        $window = $this->window();
        $page = $provider->fetchChanges($this->connection(Provider::Microsoft), $source, SyncCursor::empty(), $window);
        self::assertStringContainsString('/me/calendars/ext-1/calendarView/delta?startDateTime=', $this->http->requests[0]->url);
        self::assertStringContainsString('outlook.timezone="UTC"', $this->http->requests[0]->headers['Prefer']);
        self::assertSame($next, $page->nextPage->get('next_link'));
        self::assertTrue($page->nextPage->boundWindow->equals($window));
        $single = $page->items[0];
        self::assertSame('Europe/Berlin', $single->timezone);
        self::assertSame(OccurrenceStatus::Tentative, $single->status);
        self::assertTrue($single->isPrivate);
        self::assertTrue($single->expanded);
        self::assertSame('2026-12-24', $page->items[1]->start->format('Y-m-d'), 'All-day date rounded back from the UTC shift');
        self::assertSame('2026-12-24', $page->items[1]->recurrenceId);

        $delta = 'https://graph.microsoft.com/v1.0/me/calendars/ext-1/calendarView/delta?$deltatoken=D1';
        $this->http->push(200, ['@odata.deltaLink' => $delta, 'value' => [['id' => 'e1', '@removed' => ['reason' => 'deleted']], ['id' => 'e3', 'isCancelled' => true]]]);
        $last = $provider->fetchChanges($this->connection(Provider::Microsoft), $source, $page->nextPage, $window);
        self::assertSame($next, $this->http->requests[1]->url, 'nextLink used unchanged');
        self::assertSame(['e1', 'e3'], $last->removedItemIds);
        self::assertSame($delta, $last->finalCursor->get('delta_link'));
        self::assertTrue($last->fullSnapshot, 'Initial round is a full snapshot');

        $this->http->push(429, ['error' => ['code' => 'TooManyRequests']], ['Retry-After' => '120']);
        try {
            $provider->fetchChanges($this->connection(Provider::Microsoft), $source, $last->finalCursor, $window);
            self::fail('expected exception');
        } catch (CalendarException $e) {
            self::assertSame(ErrorCode::RateLimited, $e->errorCode);
            self::assertSame(120, $e->retryAfterSeconds);
        }
        self::assertSame($delta, $this->http->requests[2]->url);
    }

    public function testGraphRejectsForeignContinuationLinks(): void
    {
        $this->expectException(CalendarException::class);
        (new GraphCalendarProvider($this->api(Provider::Microsoft)))->fetchChanges(
            $this->connection(Provider::Microsoft),
            TestData::source(1),
            new SyncCursor(['delta_link' => 'https://evil.example/delta']),
            $this->window()
        );
    }

    public function testPkceAuthorizationRequest(): void
    {
        $service = new OAuthService($this->http, $this->credentials, $this->clock);
        $request = $service->authorizationRequest($this->connection(Provider::Microsoft), 'https://site.example/cb');
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize', $request['url']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame(OAuthService::base64Url(hash('sha256', $request['verifier'], true)), $query['code_challenge']);
        self::assertSame($request['state'], $query['state']);
        self::assertStringContainsString('Calendars.Read', (string) $query['scope']);
        self::assertStringNotContainsString('Files.', (string) $query['scope'], 'No file scopes in V1');
    }

    public function testInvalidGrantRequiresReauthorization(): void
    {
        $this->credentials->secrets[1]['expires_at'] = '0';
        $this->http->push(400, ['error' => 'invalid_grant', 'error_description' => 'refresh token revoked']);
        try {
            (new OAuthService($this->http, $this->credentials, $this->clock))->accessToken($this->connection(Provider::Google));
            self::fail('expected exception');
        } catch (CalendarException $e) {
            self::assertSame(ErrorCode::AuthRequired, $e->errorCode);
            self::assertStringNotContainsString('ref-1', $e->getMessage());
        }
        self::assertStringContainsString('refresh_token=ref-1', (string) $this->http->requests[0]->body);
        self::assertSame(OAuthEndpoints::GOOGLE_HOSTS, $this->http->requests[0]->allowedHosts);
    }
}
