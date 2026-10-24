<?php
/**
 * Uninstall handler. Data is only removed when "delete data on uninstall" was enabled in the settings.
 *
 * @package sw-cloudcalendar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/sw-cloudcalendar.php';

if ( class_exists( \SW\Calendar\Lifecycle::class ) ) {
	\SW\Calendar\Lifecycle::uninstall();
}
