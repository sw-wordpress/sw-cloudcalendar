<?php

declare(strict_types=1);

namespace SW\Calendar\Domain;

/**
 * Typed failure of the calendar core. The message is a safe English text without secrets, URLs with tokens or
 * calendar contents; the WordPress layer translates it for the UI.
 */
class CalendarException extends \RuntimeException
{
    /**
     * @param array<string, scalar|null> $context Safe diagnostic fields (no secrets, no event contents).
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return $this->errorCode->isTransient();
    }
}
