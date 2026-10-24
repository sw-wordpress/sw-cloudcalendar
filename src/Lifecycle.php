<?php
/**
 * Activation, deactivation and uninstall.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar;

use SW\Calendar\Infrastructure\Database\Schema;
use SW\Calendar\Infrastructure\WordPress\Capabilities;
use SW\Calendar\Infrastructure\WordPress\QueueRunner;
use SW\Calendar\Infrastructure\WordPress\Settings;
use SW\Calendar\Infrastructure\WordPress\StorageGuard;
use SW\Calendar\Infrastructure\WordPress\TransientCache;
use SW\Calendar\Presentation\Admin\TemplateManager;

final class Lifecycle {

	/**
	 * @param bool $network_wide Network activation (multisite): every site gets its own tables on first use.
	 */
	public static function activate( bool $network_wide = false ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature dictated by register_activation_hook().
		self::activate_site();
	}

	public static function activate_site(): void {
		Schema::migrate();
		Capabilities::grant_defaults();
		( new TemplateManager( Services::instance() ) )->seed_presets();
		QueueRunner::ensure_scheduled();
	}

	/** Deactivation keeps all data; only scheduled events are removed. */
	public static function deactivate(): void {
		QueueRunner::unschedule();
	}

	/**
	 * Removes data only when "delete data on uninstall" was enabled. Tokens are revoked where possible.
	 */
	public static function uninstall(): void {
		QueueRunner::unschedule();
		if ( empty( Settings::get( 'delete_data_on_uninstall' ) ) ) {
			return;
		}
		$services = Services::instance();
		foreach ( $services->connections()->all() as $connection ) {
			try {
				if ( \SW\Calendar\Domain\Connection\AuthMode::OAuth === $connection->authMode ) {
					$services->oauth()->revoke( $connection );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		$storage = $services->storage_guard()->state();
		$root    = (string) ( $storage['fallback'] ?? '' );
		Schema::drop_all();
		Capabilities::revoke_all();
		foreach ( array( Settings::OPTION, StorageGuard::OPTION, TransientCache::GENERATION_OPTION, TransientCache::STATS_OPTION, QueueRunner::LAST_TICK_OPTION, QueueRunner::LAST_MAINTENANCE_OPTION ) as $option ) {
			delete_option( $option );
		}
		// Only the plugin's own fallback directory below uploads is deleted; a configured external path is left alone.
		if ( '' !== $root && ! defined( 'SW_CALENDAR_PRIVATE_STORAGE_PATH' ) && str_contains( $root, 'sw-cloudcalendar-private-' ) && is_dir( $root ) ) {
			self::remove_tree( $root );
		}
	}

	private static function remove_tree( string $dir ): void {
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
