<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;

final class TemplateValidationException extends CalendarException
{
    /**
     * @param list<TemplateSyntaxError> $errors
     */
    public function __construct(public readonly array $errors)
    {
        $first = $errors[0] ?? null;
        parent::__construct(
            ErrorCode::InvalidTemplate,
            $first === null ? 'Invalid template.' : sprintf('Line %d, column %d: %s', $first->line, $first->column, $first->message),
        );
    }
}
