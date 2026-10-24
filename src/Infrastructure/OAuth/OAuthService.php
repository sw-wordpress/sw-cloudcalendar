<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\OAuth;

use SW\Calendar\Application\Http\HttpRequest;
use SW\Calendar\Application\Port\CredentialStoreInterface;
use SW\Calendar\Application\Port\HttpClientInterface;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Support\ClockInterface;
use SW\Calendar\Support\Redactor;

/**
 * OAuth 2.0 authorization code flow with PKCE (S256) and state, token refresh and revocation.
 *
 * Secrets (client secret, refresh/access tokens) are only read from and written to the encrypted credential store.
 * State and verifier are kept server side by the caller (single use, short lifetime).
 */
final class OAuthService
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CredentialStoreInterface $credentials,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{url: string, state: string, verifier: string}
     */
    public function authorizationRequest(Connection $connection, string $redirectUri): array
    {
        $endpoints = OAuthEndpoints::for($connection);
        $clientId = (string) $connection->setting('client_id', '');
        if ($clientId === '') {
            throw new CalendarException(ErrorCode::AuthRequired, 'The client ID is missing.');
        }
        $state = self::base64Url(random_bytes(32));
        $verifier = self::base64Url(random_bytes(48));
        $challenge = self::base64Url(hash('sha256', $verifier, true));
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $endpoints->scopes),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        if (str_contains($endpoints->authorizeUrl, 'google.com')) {
            $params['access_type'] = 'offline';
            $params['prompt'] = 'consent';
            $params['include_granted_scopes'] = 'false';
        } else {
            $params['response_mode'] = 'query';
            $params['prompt'] = 'select_account';
        }
        return ['url' => $endpoints->authorizeUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), 'state' => $state, 'verifier' => $verifier];
    }

    /**
     * Exchanges the authorization code and stores the tokens.
     */
    public function exchangeCode(Connection $connection, string $code, string $verifier, string $redirectUri): void
    {
        $tokens = $this->tokenRequest($connection, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ]);
        if (($tokens['refresh_token'] ?? '') === '') {
            throw new CalendarException(ErrorCode::AuthRequired, 'The provider did not return a refresh token (offline access was not granted).');
        }
        $this->store($connection, $tokens);
    }

    /**
     * Valid access token; refreshes it when it expires within the next minute.
     */
    public function accessToken(Connection $connection, bool $forceRefresh = false): string
    {
        $secrets = $this->credentials->get($connection->id);
        $expires = (int) ($secrets['expires_at'] ?? 0);
        if (!$forceRefresh && ($secrets['access_token'] ?? '') !== '' && $expires > $this->clock->now()->getTimestamp() + 60) {
            return $secrets['access_token'];
        }
        $refresh = $secrets['refresh_token'] ?? '';
        if ($refresh === '') {
            throw new CalendarException(ErrorCode::AuthRequired, 'The connection has no refresh token; please reconnect.');
        }
        $tokens = $this->tokenRequest($connection, ['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        $this->store($connection, $tokens + ['refresh_token' => $refresh]);
        return (string) $tokens['access_token'];
    }

    /**
     * Revokes the refresh token where the provider supports it (Google). Microsoft has no token revocation endpoint
     * for this flow; tokens are deleted locally and expire at the provider.
     */
    public function revoke(Connection $connection): bool
    {
        $endpoints = OAuthEndpoints::for($connection);
        $secrets = $this->credentials->get($connection->id);
        $token = $secrets['refresh_token'] ?? $secrets['access_token'] ?? '';
        if ($endpoints->revokeUrl === null || $token === '') {
            return false;
        }
        try {
            $response = $this->http->send(new HttpRequest(
                'POST',
                $endpoints->revokeUrl,
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query(['token' => $token]),
                15,
                65536,
                null,
                $endpoints->hosts,
            ));
            return $response->status === 200;
        } catch (CalendarException) {
            return false;
        }
    }

    /**
     * @param array<string, string> $params
     * @return array<string, string>
     */
    private function tokenRequest(Connection $connection, array $params): array
    {
        $endpoints = OAuthEndpoints::for($connection);
        $params['client_id'] = (string) $connection->setting('client_id', '');
        $secret = $this->credentials->get($connection->id)['client_secret'] ?? '';
        if ($secret !== '') {
            $params['client_secret'] = $secret;
        }
        if (str_contains($endpoints->tokenUrl, 'microsoftonline')) {
            $params['scope'] = implode(' ', $endpoints->scopes);
        }
        $response = $this->http->send(new HttpRequest(
            'POST',
            $endpoints->tokenUrl,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query($params, '', '&'),
            20,
            262144,
            null,
            $endpoints->hosts,
        ));
        $data = $response->json() ?? [];
        if ($response->status !== 200 || !isset($data['access_token'])) {
            $error = (string) ($data['error'] ?? ('http_' . $response->status));
            if ($response->status === 429 || $response->status >= 500) {
                throw new CalendarException(ErrorCode::RateLimited, 'Token endpoint temporarily unavailable (' . $error . ').', $response->retryAfterSeconds());
            }
            // invalid_grant: refresh token revoked/expired -> administrator must reconnect.
            throw new CalendarException(ErrorCode::AuthRequired, 'Token request failed: ' . Redactor::text($error, 60));
        }
        $tokens = [];
        foreach (['access_token', 'refresh_token', 'scope', 'id_token'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $tokens[$key] = $data[$key];
            }
        }
        $tokens['expires_at'] = (string) ($this->clock->now()->getTimestamp() + max(60, (int) ($data['expires_in'] ?? 3600)));
        return $tokens;
    }

    /**
     * @param array<string, string> $tokens
     */
    private function store(Connection $connection, array $tokens): void
    {
        unset($tokens['id_token']);
        $this->credentials->update($connection->id, $tokens);
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
