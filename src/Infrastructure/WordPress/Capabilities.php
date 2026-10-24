<?php
/**
 * The plugin capability (SPECIFICATIONS.md §16).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

final class Capabilities {

	public const MANAGE = 'manage_sw_calendar';

	/** Grants the capability to administrators of the current site. Editors do not get it implicitly. */
	public static function grant_defaults(): void {
		$role = get_role( 'administrator' );
		if ( null !== $role && ! $role->has_cap( self::MANAGE ) ) {
			$role->add_cap( self::MANAGE );
		}
	}

	public static function revoke_all(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::MANAGE );
		}
	}

	public static function current_user_can_manage(): bool {
		return current_user_can( self::MANAGE );
	}
}
