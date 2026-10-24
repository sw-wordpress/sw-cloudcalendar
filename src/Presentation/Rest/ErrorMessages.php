<?php
/**
 * Translatable UI messages for machine readable error codes.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Rest;

use SW\Calendar\Domain\ErrorCode;

final class ErrorMessages {

	public static function for( ErrorCode $code, string $fallback = '' ): string {
		return match ( $code ) {
			ErrorCode::AuthRequired => __( 'The connection must be authorized again.', 'sw-cloudcalendar' ),
			ErrorCode::TokenExpired => __( 'The access token has expired.', 'sw-cloudcalendar' ),
			ErrorCode::RateLimited => __( 'The provider limits requests at the moment. The synchronisation is retried later.', 'sw-cloudcalendar' ),
			ErrorCode::SyncCursorInvalid => __( 'The synchronisation state has expired; a full synchronisation is required.', 'sw-cloudcalendar' ),
			ErrorCode::FetchFailed => '' !== $fallback ? $fallback : __( 'The provider could not be reached.', 'sw-cloudcalendar' ),
			ErrorCode::AttachmentUnsupported => __( 'This attachment type is not supported.', 'sw-cloudcalendar' ),
			ErrorCode::PublicStatusUnknown => __( 'The public availability of the file could not be verified.', 'sw-cloudcalendar' ),
			ErrorCode::DownloadTooLarge => __( 'The file exceeds the size limit.', 'sw-cloudcalendar' ),
			ErrorCode::StorageUnsafe => __( 'The private file storage is not protected against web access.', 'sw-cloudcalendar' ),
			ErrorCode::InvalidTemplate => __( 'The template is invalid.', 'sw-cloudcalendar' ),
			ErrorCode::AccessDenied => __( 'Access denied.', 'sw-cloudcalendar' ),
			ErrorCode::ProviderUnsupported => __( 'This function is not supported by the provider.', 'sw-cloudcalendar' ),
			ErrorCode::InvalidInput => __( 'Invalid input.', 'sw-cloudcalendar' ),
			ErrorCode::NotFound => __( 'Not found.', 'sw-cloudcalendar' ),
			ErrorCode::LimitExceeded => __( 'A configured limit was exceeded.', 'sw-cloudcalendar' ),
			ErrorCode::Locked => __( 'Another synchronisation is running.', 'sw-cloudcalendar' ),
		};
	}
}
