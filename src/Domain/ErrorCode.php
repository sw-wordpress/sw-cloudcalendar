<?php

declare(strict_types=1);

namespace SW\Calendar\Domain;

/**
 * Machine readable error codes (SPECIFICATIONS.md §5). APIs return the code plus a translatable message.
 */
enum ErrorCode: string
{
    case AuthRequired = 'AUTH_REQUIRED';
    case TokenExpired = 'TOKEN_EXPIRED';
    case RateLimited = 'RATE_LIMITED';
    case SyncCursorInvalid = 'SYNC_CURSOR_INVALID';
    case FetchFailed = 'FETCH_FAILED';
    case AttachmentUnsupported = 'ATTACHMENT_UNSUPPORTED';
    case PublicStatusUnknown = 'PUBLIC_STATUS_UNKNOWN';
    case DownloadTooLarge = 'DOWNLOAD_TOO_LARGE';
    case StorageUnsafe = 'STORAGE_UNSAFE';
    case InvalidTemplate = 'INVALID_TEMPLATE';
    case AccessDenied = 'ACCESS_DENIED';
    case ProviderUnsupported = 'PROVIDER_UNSUPPORTED';
    case InvalidInput = 'INVALID_INPUT';
    case NotFound = 'NOT_FOUND';
    case LimitExceeded = 'LIMIT_EXCEEDED';
    case Locked = 'LOCKED';

    /** Whether a later retry of the same operation can succeed without administrator action. */
    public function isTransient(): bool
    {
        return match ($this) {
            self::RateLimited, self::FetchFailed, self::Locked, self::TokenExpired => true,
            default => false,
        };
    }
}
