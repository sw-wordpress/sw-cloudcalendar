<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Provider\CalendarProviderInterface;

interface ProviderRegistryInterface
{
    public function get(Provider $provider): CalendarProviderInterface;
}
