<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Template;

final class Template
{
    /**
     * @param array<string, mixed>|null $visualSchema Block list of the visual editor (null for HTML templates).
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly TemplateKind $kind,
        public readonly EditorMode $editorMode,
        public readonly string $body,
        public readonly string $css,
        public readonly ?array $visualSchema,
        public readonly int $revision,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly ?string $presetKey = null,
    ) {
    }
}
