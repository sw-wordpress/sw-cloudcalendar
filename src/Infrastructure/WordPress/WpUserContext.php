<?php
/**
 * Roles of WordPress users for the source ACL.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

use SW\Calendar\Application\Port\UserContextInterface;

final class WpUserContext implements UserContextInterface {

	public function rolesOf( ?int $userId ): ?array {
		if ( null === $userId || $userId <= 0 ) {
			return null;
		}
		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return null;
		}
		// On multisite a user without a role on this site is "logged in" but not a member: no role based access.
		return array_values( array_map( 'strval', (array) $user->roles ) );
	}
}
