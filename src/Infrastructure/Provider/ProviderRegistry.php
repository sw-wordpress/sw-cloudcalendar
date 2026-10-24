<?php

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Provider;

use SW\Calendar\Application\Port\ProviderRegistryInterface;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Provider\CalendarProviderInterface;

final class ProviderRegistry implements ProviderRegistryInterface
{
    /** @var array<string, \Closure(): CalendarProviderInterface> */
    private array $factories;
    /** @var array<string, CalendarProviderInterface> */
    private array $instances = [];

    /**
     * @param array<string, \Closure(): CalendarProviderInterface> $factories Provider value => lazy factory.
     */
    public function __construct(array $factories)
    {
        $this->factories = $factories;
    }

    public function get(Provider $provider): CalendarProviderInterface
    {
        if (!isset($this->factories[$provider->value])) {
            throw new CalendarException(ErrorCode::ProviderUnsupported, 'Provider not available.');
        }
        return $this->instances[$provider->value] ??= ($this->factories[$provider->value])();
    }
}
