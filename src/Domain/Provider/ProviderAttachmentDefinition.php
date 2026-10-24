<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

/**
 * Attachment metadata as delivered with an event. `reference` is an opaque provider reference (Drive file id, Graph
 * attachment id, ICS URL or inline marker) and is never rendered into public markup.
 */
final class ProviderAttachmentDefinition
{
    public const KIND_FILE = 'file';
    public const KIND_CLOUD = 'cloud_reference';
    public const KIND_URL = 'url';
    public const KIND_INLINE = 'inline';
    public const KIND_ITEM = 'item';

    public function __construct(
        public readonly string $kind,
        public readonly string $reference,
        public readonly string $filename,
        public readonly string $mime = 'application/octet-stream',
        public readonly ?int $sizeBytes = null,
        public readonly ?string $versionToken = null,
    ) {
    }

    /** Stable identity of the referenced file within a source. */
    public function refHash(): string
    {
        return hash('sha256', $this->kind . "\0" . $this->reference);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'reference' => $this->reference,
            'filename' => $this->filename,
            'mime' => $this->mime,
            'size' => $this->sizeBytes,
            'version' => $this->versionToken,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['kind'] ?? self::KIND_FILE),
            (string) ($data['reference'] ?? ''),
            (string) ($data['filename'] ?? ''),
            (string) ($data['mime'] ?? 'application/octet-stream'),
            isset($data['size']) ? (int) $data['size'] : null,
            isset($data['version']) ? (string) $data['version'] : null,
        );
    }
}
