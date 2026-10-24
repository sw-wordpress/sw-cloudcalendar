<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

interface CacheInvalidatorInterface
{
    /** Called after a committed data change of a source (revision bump already done). */
    public function sourceChanged(int $sourceId): void;
}
