<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

final class ProviderEventReference
{
    public function __construct(public readonly string $providerEventId)
    {
    }
}
