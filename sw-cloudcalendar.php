<?php
/**
 * Plugin Name:       SW CloudCalendar
 * Plugin URI:        https://example.com/sw-cloudcalendar
 * Description:       Read-only integration of Google Calendar, Microsoft 365 and ICS feeds: local synchronisation, flexible templates, protected attachments.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            SW CloudCalendar contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sw-cloudcalendar
 * Domain Path:       /languages
 *
 * @package sw-cloudcalendar
 */

defined( 'ABSPATH' ) || exit;

define( 'SW_CALENDAR_VERSION', '1.0.0' );
define( 'SW_CALENDAR_FILE', __FILE__ );
define( 'SW_CALENDAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'SW_CALENDAR_URL', plugin_dir_url( __FILE__ ) );
define( 'SW_CALENDAR_MIN_PHP', '8.2' );

if ( version_compare( PHP_VERSION, SW_CALENDAR_MIN_PHP, '<' ) || ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'SW CloudCalendar requires PHP %1$s or newer with the sodium extension. You are running PHP %2$s.', 'sw-cloudcalendar' ),
						SW_CALENDAR_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

// SW_CALENDAR_AUTOLOADER may be defined by the test environment to supply its own dependency set.
$sw_calendar_autoload = defined( 'SW_CALENDAR_AUTOLOADER' ) ? SW_CALENDAR_AUTOLOADER : SW_CALENDAR_DIR . 'vendor-prefixed/autoload.php';
if ( ! defined( 'SW_CALENDAR_AUTOLOADER' ) && ! is_readable( $sw_calendar_autoload ) ) {
	// Source checkouts use Composer; release packages only ship isolated dependencies.
	$sw_calendar_autoload = SW_CALENDAR_DIR . 'vendor/autoload.php';
}
if ( ! is_readable( $sw_calendar_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'SW CloudCalendar: the vendor directory is missing. Install the release package or run "composer install".', 'sw-cloudcalendar' )
			);
		}
	);
	return;
}
$sw_calendar_loader = require $sw_calendar_autoload;
// Mozart's loader only knows the dependencies, not the plugin's own classes.
$sw_calendar_loader->addPsr4( 'SW\\Calendar\\', SW_CALENDAR_DIR . 'src/' );
unset( $sw_calendar_autoload, $sw_calendar_loader );

register_activation_hook( __FILE__, array( \SW\Calendar\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SW\Calendar\Lifecycle::class, 'deactivate' ) );

\SW\Calendar\Plugin::instance()->boot();
