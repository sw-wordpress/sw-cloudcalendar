<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

final class RenderSettings
{
    public function __construct(
        public readonly string $locale = 'en_US',
        public readonly int $weekStart = 1,
        public readonly bool $htmlCacheEnabled = true,
        public readonly string $defaultTimezone = 'UTC',
        public readonly RenderLabels $labels = new RenderLabels(),
        public readonly int $defaultLimit = 50,
    ) {
    }
}
