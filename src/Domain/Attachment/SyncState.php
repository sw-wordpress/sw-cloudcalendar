<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Attachment;

enum SyncState: string
{
    /** Waiting for the asynchronous attachment job. */
    case Pending = 'pending';
    /** Stored locally and deliverable (if the source ACL allows). */
    case Stored = 'stored';
    /** Not synchronised because of the policy (public_only, MIME, size, unsupported). */
    case Skipped = 'skipped';
    case Failed = 'failed';
    /** Removed at the provider, policy changed or source disabled: never delivered again. */
    case Revoked = 'revoked';

    public function isDeliverable(): bool
    {
        return $this === self::Stored;
    }
}
