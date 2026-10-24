<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Storage;

use SW\Calendar\Application\Port\MimeDetectorInterface;

/**
 * Content based MIME detection. Without ext-fileinfo only a few magic numbers are recognised; everything else is
 * application/octet-stream, which no allowlist contains (fail closed).
 */
final class FinfoMimeDetector implements MimeDetectorInterface
{
    public function detect(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }
        $head = (string) file_get_contents($path, false, null, 0, 16);
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($head, '%PDF-') => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
