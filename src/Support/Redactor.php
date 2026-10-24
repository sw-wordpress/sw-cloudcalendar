<?php

declare(strict_types=1);

namespace SW\Calendar\Support;

/**
 * Removes secrets from texts that may end up in logs or API responses (provider error bodies, URLs).
 */
final class Redactor
{
    public static function text(string $text, int $maxLength = 300): string
    {
        $patterns = [
            '~(access_token|refresh_token|id_token|client_secret|code|key|token|sig|signature|\$deltatoken|\$skiptoken|synctoken|pagetoken)=([^&\s"]+)~i' => '$1=[redacted]',
            '~"(access_token|refresh_token|id_token|client_secret|code|nextSyncToken|nextPageToken|@odata\.deltaLink|@odata\.nextLink)"\s*:\s*"[^"]*"~i' => '"$1":"[redacted]"',
            '#(Bearer|Basic)\s+[A-Za-z0-9\-._~+/]+=*#' => '$1 [redacted]',
            '~https?://[^\s"\']+~i' => '[url]',
        ];
        $text = (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength) . '…' : $text;
    }
}
