<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Source;

/**
 * Record of an explicit administrative publication decision (SPECIFICATIONS.md §8.1).
 */
final class Confirmation
{
    public function __construct(
        public readonly int $userId,
        public readonly \DateTimeImmutable $confirmedAt,
        public readonly int $sourceRevision,
    ) {
    }

    /**
     * @param mixed $data Stored array or null.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !isset($data['user_id'], $data['confirmed_at'])) {
            return null;
        }
        try {
            $at = new \DateTimeImmutable((string) $data['confirmed_at'], new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        return new self((int) $data['user_id'], $at, (int) ($data['source_revision'] ?? 0));
    }

    /**
     * @return array{user_id: int, confirmed_at: string, source_revision: int}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'confirmed_at' => $this->confirmedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'source_revision' => $this->sourceRevision,
        ];
    }
}
