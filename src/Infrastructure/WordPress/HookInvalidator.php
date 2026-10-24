<?php
/**
 * Cache invalidation hook point after committed source changes.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

use SW\Calendar\Application\Port\CacheInvalidatorInterface;

final class HookInvalidator implements CacheInvalidatorInterface {

	public function sourceChanged( int $sourceId ): void {
		// Render cache keys contain the source revision, so plugin caches are already invalid. The action lets page
		// cache integrations purge pages that embed this calendar.
		/**
		 * Fires after the data of a calendar source changed (sync commit, revocation, ACL change).
		 *
		 * @since 1.0.0
		 * @param int $source_id Source id.
		 */
		do_action( 'sw_calendar_source_changed', $sourceId );
	}
}
