<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

/**
 * Server-only temporary file the provider writes into, with a hard byte limit.
 */
final class DownloadTarget
{
    public function __construct(
        public readonly string $temporaryPath,
        public readonly int $maxBytes,
    ) {
    }
}
