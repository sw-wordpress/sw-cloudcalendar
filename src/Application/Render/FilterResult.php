<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

final class FilterResult
{
    /**
     * @param array<string, string> $errors  Parameter => error code.
     * @param list<string>          $unknown Unknown parameter names.
     */
    public function __construct(
        public readonly RenderFilter $filter,
        public readonly array $errors,
        public readonly array $unknown,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
