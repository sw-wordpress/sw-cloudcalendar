<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

/**
 * Marker for HTML produced by plugin renderers (gallery, file list, event list) or by the server side sanitizer.
 * Provider data must never be wrapped into this type without sanitizing it first.
 */
final class TrustedHtml implements \Stringable
{
    public function __construct(private readonly string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }

    public function isEmpty(): bool
    {
        return trim($this->html) === '';
    }
}
