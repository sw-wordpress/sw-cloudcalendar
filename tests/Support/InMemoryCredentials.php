<?php

declare(strict_types=1);

namespace SW\Calendar\Tests\Support;

use SW\Calendar\Application\Port\CredentialStoreInterface;

final class InMemoryCredentials implements CredentialStoreInterface
{
    /** @var array<int, array<string, string>> */
    public array $secrets = [];

    public function get(int $connectionId): array
    {
        return $this->secrets[$connectionId] ?? [];
    }

    public function update(int $connectionId, array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($this->secrets[$connectionId][$key]);
            } else {
                $this->secrets[$connectionId][$key] = $value;
            }
        }
    }

    public function delete(int $connectionId): void
    {
        unset($this->secrets[$connectionId]);
    }
}
