<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

final class CalendarDefinition
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $name,
        public readonly ?string $timezone = null,
        public readonly ?string $color = null,
        /** True when the calendar is known to be public at the provider, false if private, null if unknown. */
        public readonly ?bool $publicAtProvider = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'color' => $this->color,
            'public_at_provider' => $this->publicAtProvider,
        ];
    }
}
