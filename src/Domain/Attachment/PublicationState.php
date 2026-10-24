<?php

declare(strict_types=1);

namespace SW\Calendar\Domain\Attachment;

/**
 * Provider side publication status of a file (SPECIFICATIONS.md §8.2). Unknown is never treated as public.
 */
enum PublicationState: string
{
    case PublicVerified = 'PUBLIC_VERIFIED';
    case Private = 'PRIVATE';
    case Unknown = 'UNKNOWN';
    case Unsupported = 'UNSUPPORTED';
    case NotApplicable = 'NOT_APPLICABLE';
}
