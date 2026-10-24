<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

final class DownloadResult
{
    public function __construct(
        public readonly int $bytes,
        public readonly ?string $reportedMime = null,
        public readonly ?string $versionToken = null,
        public readonly ?string $filename = null,
    ) {
    }
}
