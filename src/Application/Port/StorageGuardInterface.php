<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

interface StorageGuardInterface
{
    /** Whether the private storage is verified to be inaccessible from the web. */
    public function isSafe(): bool;

    public function reason(): string;
}
