<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

use SW\Calendar\Domain\ErrorCode;

final class ConnectionTestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?ErrorCode $errorCode = null,
        public readonly ?string $accountRef = null,
    ) {
    }
}
