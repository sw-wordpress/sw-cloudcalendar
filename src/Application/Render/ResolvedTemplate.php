<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Application\Render\Template\CompiledTemplate;

final class ResolvedTemplate
{
    public function __construct(
        /** Template id, or 0 for a built-in preset. */
        public readonly int $id,
        public readonly int $revision,
        public readonly CompiledTemplate $compiled,
        /** CSS class the template's scoped CSS is bound to. */
        public readonly string $scopeClass,
        public readonly string $scopedCss,
    ) {
    }

    public function cacheToken(): string
    {
        return $this->id . ':' . $this->revision . ':' . $this->scopeClass;
    }
}
