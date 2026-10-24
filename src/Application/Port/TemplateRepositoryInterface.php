<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Domain\Template\Template;

interface TemplateRepositoryInterface
{
    public function find(int $id): ?Template;
}
