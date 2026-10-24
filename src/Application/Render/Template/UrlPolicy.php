<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

/**
 * URL scheme allowlist for rendered markup (`esc_url` equivalent for the framework independent renderer).
 */
final class UrlPolicy
{
    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function isSafe(string $url): bool
    {
        // Browsers ignore control characters and whitespace inside schemes ("java\tscript:").
        $compact = (string) preg_replace('/[\x00-\x20\x7f]+/', '', $url);
        if ($compact === '') {
            return true;
        }
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $compact, $m) === 1) {
            return in_array(strtolower($m[1]), self::SCHEMES, true);
        }
        // Relative URL: a colon before the first "/", "?" or "#" would be a scheme with unusual characters.
        $firstDelimiter = strcspn($compact, '/?#');
        return !str_contains(substr($compact, 0, $firstDelimiter), ':') && !str_starts_with($compact, '\\');
    }

    /** Returns the URL if safe, otherwise an empty string. */
    public static function sanitize(string $url): string
    {
        $url = trim($url);
        return self::isSafe($url) ? $url : '';
    }

    /** Only absolute http(s) URLs (provider links, feed URLs). */
    public static function isHttp(string $url): bool
    {
        return preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $url) === 1 && self::isSafe($url);
    }
}
