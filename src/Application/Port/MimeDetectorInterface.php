<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

interface MimeDetectorInterface
{
    /** MIME type detected from the file content (never from name or provider metadata). */
    public function detect(string $path): string;
}
