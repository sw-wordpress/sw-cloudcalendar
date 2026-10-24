<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Connection\ConnectionStatus;

interface ConnectionRepositoryInterface
{
    public function find(int $id): ?Connection;

    public function setStatus(int $id, ConnectionStatus $status): void;
}
