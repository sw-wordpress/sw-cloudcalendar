<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Source;

/**
 * One external calendar. Its visibility applies to all of its events and attachments.
 */
final class Source
{
    /**
     * @param list<string> $roles Validated, non-empty when visibility is `roles`.
     */
    public function __construct(
        public readonly int $id,
        public readonly int $connectionId,
        public readonly string $externalId,
        public readonly string $name,
        public readonly string $color,
        public readonly string $timezone,
        public readonly Visibility $visibility,
        public readonly array $roles,
        public readonly SourceSettings $settings,
        public readonly int $revision,
        public readonly SourceStatus $status,
        public readonly ?\DateTimeImmutable $lastSuccessAt = null,
    ) {
    }

    public function isReadable(): bool
    {
        return $this->status->isReadable();
    }

    public function timeZone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->timezone !== '' ? $this->timezone : 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
