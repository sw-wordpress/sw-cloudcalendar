<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

/**
 * Encrypted secrets of a connection (client secret, tokens, API key, ICS auth header). Never returned by REST.
 */
interface CredentialStoreInterface
{
    /**
     * @return array<string, string>
     */
    public function get(int $connectionId): array;

    /**
     * Merges the given values into the stored secrets.
     *
     * @param array<string, string|null> $values Null removes a key.
     */
    public function update(int $connectionId, array $values): void;

    public function delete(int $connectionId): void;
}
