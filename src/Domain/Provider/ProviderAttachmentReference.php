<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

/**
 * Attachment reference plus the provider event it belongs to (needed by Graph to address the attachment).
 */
final class ProviderAttachmentReference
{
    public function __construct(
        public readonly ProviderAttachmentDefinition $definition,
        public readonly string $providerEventId,
    ) {
    }
}
