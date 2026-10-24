<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Attachments;

/**
 * Content type rules for delivery: active content is never accepted, images are delivered inline only when they
 * are raster images, every file gets an extension matching its detected type.
 */
final class MimePolicy
{
    public const RASTER_IMAGES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Never stored, even if an administrator allowed them by mistake. */
    public const FORBIDDEN = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml', 'text/javascript',
        'application/javascript', 'application/x-httpd-php', 'application/x-php', 'text/x-php', 'application/x-sh',
        'application/x-msdownload', 'application/x-executable', 'application/java-archive', 'application/x-shockwave-flash',
    ];

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
        'application/rtf' => 'rtf',
    ];

    public static function isRasterImage(string $mime): bool
    {
        return in_array($mime, self::RASTER_IMAGES, true);
    }

    public static function isForbidden(string $mime): bool
    {
        return in_array(strtolower($mime), self::FORBIDDEN, true) || str_contains(strtolower($mime), 'script');
    }

    /**
     * Office formats are ZIP containers: finfo often reports application/zip or application/octet-stream for them.
     * The declared type is accepted only if the detected type is compatible with it.
     */
    public static function reconcile(string $detected, string $declared, string $filename): string
    {
        $detected = strtolower($detected);
        $declared = strtolower($declared);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $ooxml = [
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
        ];
        if (in_array($detected, ['application/zip', 'application/octet-stream'], true) && isset($ooxml[$extension])) {
            return $ooxml[$extension];
        }
        if ($detected === 'application/octet-stream' && in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            return array_search($extension, self::EXTENSIONS, true) ?: $detected;
        }
        if ($detected === 'application/csv' || ($detected === 'text/plain' && ($declared === 'text/csv' || $extension === 'csv'))) {
            return 'text/csv';
        }
        if ($detected === 'text/rtf') {
            return 'application/rtf';
        }
        if ($detected === 'application/cdfv2' && in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            return (string) array_search($extension, self::EXTENSIONS, true);
        }
        return $detected;
    }

    /**
     * Download name with an extension that matches the detected type (prevents "invoice.pdf" being an HTML file).
     */
    public static function downloadName(string $filename, string $mime): string
    {
        $extension = self::EXTENSIONS[$mime] ?? 'bin';
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N}\-_. ()]+/u', '_', $base), ' ._');
        if ($base === '') {
            $base = 'attachment';
        }
        return mb_substr($base, 0, 150) . '.' . $extension;
    }
}
