<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

use SW\Calendar\Domain\Template\EditorMode;
use SW\Calendar\Domain\Template\Template;
use SW\Calendar\Domain\Template\TemplateKind;

interface TemplateRepositoryInterface
{
    public function find(int $id): ?Template;

    /**
     * @return list<Template>
     */
    public function all(?TemplateKind $kind = null): array;

    /**
     * @param array<string, mixed>|null $schema
     */
    public function save(?int $id, string $name, TemplateKind $kind, EditorMode $mode, string $body, string $css, ?array $schema, ?string $preset_key = null): int;
}
