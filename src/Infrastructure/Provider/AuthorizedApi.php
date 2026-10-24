<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Provider;

use SW\Calendar\Application\Http\HttpRequest;
use SW\Calendar\Application\Http\HttpResponse;
use SW\Calendar\Application\Port\CredentialStoreInterface;
use SW\Calendar\Application\Port\HttpClientInterface;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Infrastructure\OAuth\OAuthService;
use SW\Calendar\Support\Redactor;

/**
 * Authenticated JSON requests to a cloud API on a fixed host allowlist. A 401 triggers exactly one token refresh.
 * Error responses are mapped to the calendar error codes; bodies are redacted before they reach any message.
 */
final class AuthorizedApi
{
    /**
     * @param list<string> $hosts
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly OAuthService $oauth,
        private readonly CredentialStoreInterface $credentials,
        private readonly array $hosts,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(Connection $connection, string $url, array $headers = [], ?string $sink = null, int $maxBytes = 20971520): HttpResponse
    {
        $request = new HttpRequest('GET', $this->withApiKey($connection, $url), $headers + ['Accept' => 'application/json'], null, 30, $maxBytes, $sink, $this->hosts);
        $response = $this->http->send($this->authorize($connection, $request, false));
        if ($response->status === 401 && $connection->authMode === AuthMode::OAuth) {
            $response = $this->http->send($this->authorize($connection, $request, true));
        }
        return $response;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function json(Connection $connection, string $url, array $headers = []): array
    {
        $response = $this->get($connection, $url, $headers);
        $this->assertOk($response);
        $data = $response->json();
        if ($data === null) {
            throw new CalendarException(ErrorCode::FetchFailed, 'The provider returned invalid JSON.');
        }
        return $data;
    }

    public function assertOk(HttpResponse $response): void
    {
        if ($response->status >= 200 && $response->status < 300) {
            return;
        }
        $data = $response->json() ?? [];
        $reason = '';
        if (isset($data['error']) && is_array($data['error'])) {
            $reason = (string) ($data['error']['code'] ?? $data['error']['status'] ?? '');
            foreach ((array) ($data['error']['errors'] ?? []) as $error) {
                if (is_array($error) && isset($error['reason'])) {
                    $reason = (string) $error['reason'];
                }
            }
        } elseif (isset($data['error'])) {
            $reason = (string) $data['error'];
        }
        $message = sprintf('Provider request failed (HTTP %d%s).', $response->status, $reason !== '' ? ', ' . Redactor::text($reason, 60) : '');
        $code = match (true) {
            $response->status === 410, in_array($reason, ['fullSyncRequired', 'SyncStateNotFound', 'syncStateNotFound', 'resyncRequired'], true) => ErrorCode::SyncCursorInvalid,
            $response->status === 429, $response->status === 503, in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true) => ErrorCode::RateLimited,
            $response->status === 401 => ErrorCode::AuthRequired,
            $response->status === 403 => ErrorCode::AccessDenied,
            $response->status === 404 => ErrorCode::NotFound,
            default => ErrorCode::FetchFailed,
        };
        throw new CalendarException($code, $message, $response->retryAfterSeconds() ?? ($code === ErrorCode::RateLimited ? 60 : null));
    }

    private function authorize(Connection $connection, HttpRequest $request, bool $forceRefresh): HttpRequest
    {
        if ($connection->authMode !== AuthMode::OAuth) {
            return $request;
        }
        $token = $this->oauth->accessToken($connection, $forceRefresh);
        return new HttpRequest(
            $request->method,
            $request->url,
            ['Authorization' => 'Bearer ' . $token] + $request->headers,
            $request->body,
            $request->timeout,
            $request->maxBytes,
            $request->sinkFile,
            $request->allowedHosts,
        );
    }

    private function withApiKey(Connection $connection, string $url): string
    {
        if ($connection->authMode !== AuthMode::ApiKey) {
            return $url;
        }
        $key = $this->credentials->get($connection->id)['api_key'] ?? '';
        if ($key === '') {
            throw new CalendarException(ErrorCode::AuthRequired, 'The API key is missing.');
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'key=' . rawurlencode($key);
    }
}
