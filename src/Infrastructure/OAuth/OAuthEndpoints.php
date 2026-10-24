<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\OAuth;

use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Connection\Provider;

/**
 * Fixed OAuth endpoints and least-privilege scopes per provider (SPECIFICATIONS.md §6.1, §6.2). File scopes are only
 * requested when the connection is configured for attachment access.
 */
final class OAuthEndpoints
{
    public const GOOGLE_HOSTS = ['accounts.google.com', 'oauth2.googleapis.com', 'www.googleapis.com', '.googleusercontent.com'];
    public const MICROSOFT_HOSTS = ['login.microsoftonline.com', 'graph.microsoft.com'];

    public function __construct(
        public readonly string $authorizeUrl,
        public readonly string $tokenUrl,
        public readonly ?string $revokeUrl,
        /** @var list<string> */
        public readonly array $scopes,
        /** @var list<string> */
        public readonly array $hosts,
    ) {
    }

    public static function for(Connection $connection): self
    {
        $files = (bool) $connection->setting('attachment_access', false);
        if ($connection->provider === Provider::Google) {
            $scopes = ['https://www.googleapis.com/auth/calendar.readonly'];
            if ($files) {
                $scopes[] = 'https://www.googleapis.com/auth/drive.readonly';
            }
            return new self(
                'https://accounts.google.com/o/oauth2/v2/auth',
                'https://oauth2.googleapis.com/token',
                'https://oauth2.googleapis.com/revoke',
                $scopes,
                self::GOOGLE_HOSTS,
            );
        }
        if ($connection->provider === Provider::Microsoft) {
            $tenant = (string) $connection->setting('tenant_id', 'organizations');
            if (preg_match('/^[A-Za-z0-9.\-]{1,100}$/', $tenant) !== 1) {
                $tenant = 'organizations';
            }
            $scopes = ['offline_access', 'openid', 'User.Read', 'Calendars.Read'];
            if ((bool) $connection->setting('shared_calendars', false)) {
                $scopes[] = 'Calendars.Read.Shared';
            }
            return new self(
                'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/authorize',
                'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token',
                null,
                $scopes,
                self::MICROSOFT_HOSTS,
            );
        }
        throw new \InvalidArgumentException('Provider does not use OAuth.');
    }
}
