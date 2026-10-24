<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

use SW\Calendar\Domain\Attachment\PublicationState;

final class AttachmentInspection
{
    public function __construct(
        public readonly PublicationState $publicationState,
        public readonly ?string $mime = null,
        public readonly ?int $sizeBytes = null,
        public readonly ?string $versionToken = null,
        public readonly ?string $filename = null,
        /** Safe diagnostic reason, e.g. why the public status could not be verified. */
        public readonly ?string $reason = null,
    ) {
    }
}
