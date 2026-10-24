<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

/**
 * Key/value cache (transients in WordPress). Values are strings; callers never store PHP objects.
 */
interface CacheInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
