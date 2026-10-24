<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

interface ImagePreviewInterface
{
    /**
     * Creates a downscaled, EXIF-rotated preview of a raster image into `$target`. Returns false if no preview should
     * be used (e.g. animated GIF, image editor unavailable, too many pixels).
     */
    public function createPreview(string $source, string $target, string $mime): bool;
}
