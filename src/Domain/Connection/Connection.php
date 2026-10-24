<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Connection;

/**
 * A provider account or ICS configuration. Secrets are never part of this object (see SecretVaultInterface).
 */
final class Connection
{
    /**
     * @param array<string, mixed> $settings Non secret settings (client id, tenant, feed URL, scopes ...).
     */
    public function __construct(
        public readonly int $id,
        public readonly Provider $provider,
        public readonly string $name,
        public readonly AuthMode $authMode,
        public readonly ConnectionStatus $status,
        public readonly ?string $accountRef,
        public readonly array $settings,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function withStatus(ConnectionStatus $status): self
    {
        return new self(
            $this->id,
            $this->provider,
            $this->name,
            $this->authMode,
            $status,
            $this->accountRef,
            $this->settings,
            $this->createdAt,
            $this->updatedAt,
        );
    }
}
