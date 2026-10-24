<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Connection;

enum AuthMode: string
{
    /** OAuth 2.0 authorization code with PKCE (Google, Microsoft delegated). */
    case OAuth = 'oauth';
    /** Google API key, only for calendars that are public at Google. */
    case ApiKey = 'api_key';
    /** ICS feed without authentication. */
    case None = 'none';
    /** ICS feed with an explicitly configured, encrypted Authorization header. */
    case HttpHeader = 'http_header';

    /**
     * @return list<self>
     */
    public static function allowedFor(Provider $provider): array
    {
        return match ($provider) {
            Provider::Google => [self::OAuth, self::ApiKey],
            Provider::Microsoft => [self::OAuth],
            Provider::Ics => [self::None, self::HttpHeader],
        };
    }
}
