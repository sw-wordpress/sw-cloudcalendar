<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Local;

final class LocalSourceRegistry
{
    public const API_VERSION = 1;

    /** @var array<string, LocalEventSourceInterface> */
    private array $adapters = [];

    public function register(string $id, LocalEventSourceInterface $adapter, int $apiVersion = self::API_VERSION): void
    {
        if ($apiVersion !== self::API_VERSION || preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $id) !== 1 || isset($this->adapters[$id])) {
            throw new \InvalidArgumentException('Unsupported API version, invalid or duplicate local source id.');
        }
        $this->adapters[$id] = $adapter;
    }

    public function get(string $id): ?LocalEventSourceInterface
    {
        return $this->adapters[$id] ?? null;
    }

    /** @return list<array{id: string, name: string}> */
    public function choices(): array
    {
        $result = [];
        foreach ($this->adapters as $id => $adapter) {
            $result[] = ['id' => $id, 'name' => $adapter->name()];
        }
        return $result;
    }
}
