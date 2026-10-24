<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

interface HtmlSanitizerInterface
{
    /**
     * Sanitizes external HTML (provider descriptions) to a small formatting allowlist.
     */
    public function sanitizeDescription(string $html): string;
}
