<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

/**
 * One validation problem with 1-based position, reported to the editor.
 */
final class TemplateSyntaxError
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $line,
        public readonly int $column,
        public readonly array $params = [],
    ) {
    }

    /**
     * @return array{code: string, message: string, line: int, column: int, params: array<string, string>}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message, 'line' => $this->line, 'column' => $this->column, 'params' => $this->params];
    }
}
