<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Provider;

/**
 * Declared abilities of a provider adapter. A missing capability must never be presented as working functionality.
 */
final class ProviderCapabilities
{
    public function __construct(
        public readonly bool $listCalendars,
        public readonly bool $incrementalSync,
        public readonly bool $etag,
        /** Recurring masters are delivered and must be expanded locally. */
        public readonly bool $recurrence,
        public readonly bool $fileAttachments,
        public readonly bool $cloudReferenceDownloads,
        public readonly bool $publicAttachmentVerification,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'list_calendars' => $this->listCalendars,
            'incremental_sync' => $this->incrementalSync,
            'etag' => $this->etag,
            'recurrence' => $this->recurrence,
            'file_attachments' => $this->fileAttachments,
            'cloud_reference_downloads' => $this->cloudReferenceDownloads,
            'public_attachment_verification' => $this->publicAttachmentVerification,
        ];
    }
}
