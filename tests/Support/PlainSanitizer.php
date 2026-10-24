<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Support;

use SW\Calendar\Application\Port\HtmlSanitizerInterface;

final class PlainSanitizer implements HtmlSanitizerInterface
{
    public function sanitizeDescription(string $html): string
    {
        return strip_tags($html, '<p><br><strong><em><a><ul><ol><li>');
    }
}
