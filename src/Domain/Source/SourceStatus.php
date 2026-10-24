<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Source;

enum SourceStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Error = 'error';
    case Disabled = 'disabled';

    /**
     * Whether the source's data may be shown. Paused and error sources keep serving their last valid data
     * (SPECIFICATIONS.md §7.2); drafts were never published and disabled sources are blocked immediately.
     */
    public function isReadable(): bool
    {
        return match ($this) {
            self::Active, self::Paused, self::Error => true,
            self::Draft, self::Disabled => false,
        };
    }

    public function isSyncable(): bool
    {
        return $this === self::Active || $this === self::Error;
    }
}
