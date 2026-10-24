<?php
/**
 * Global plugin settings (option `swc_settings`).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

final class Settings {

	public const OPTION = 'sw_calendar_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'html_cache'               => true,
			'delete_data_on_uninstall' => false,
			'cron_mode'                => 'wp',
			'log_level'                => 'info',
			'log_retention_days'       => 30,
			'run_retention_days'       => 90,
			'global_quota_mb'          => 2048,
			'revoked_file_grace_hours' => 24,
			'public_rate_limit'        => 120,
			'default_view_timezone'    => '',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Validates and stores a partial update.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, string> Field => error code (empty on success).
	 */
	public static function update( array $input ): array {
		$current = self::all();
		$errors  = array();
		$ints    = array(
			'log_retention_days'       => array( 1, 3650 ),
			'run_retention_days'       => array( 1, 3650 ),
			'global_quota_mb'          => array( 1, 1048576 ),
			'revoked_file_grace_hours' => array( 0, 720 ),
			'public_rate_limit'        => array( 0, 10000 ),
		);
		foreach ( $input as $key => $value ) {
			if ( ! array_key_exists( $key, self::defaults() ) ) {
				$errors[ $key ] = 'unknown';
				continue;
			}
			if ( isset( $ints[ $key ] ) ) {
				$int = filter_var( $value, FILTER_VALIDATE_INT );
				if ( false === $int || $int < $ints[ $key ][0] || $int > $ints[ $key ][1] ) {
					$errors[ $key ] = 'out_of_range';
					continue;
				}
				$current[ $key ] = $int;
				continue;
			}
			switch ( $key ) {
				case 'html_cache':
				case 'delete_data_on_uninstall':
					$current[ $key ] = (bool) $value;
					break;
				case 'cron_mode':
					if ( ! in_array( $value, array( 'wp', 'system' ), true ) ) {
						$errors[ $key ] = 'invalid';
						break;
					}
					$current[ $key ] = $value;
					break;
				case 'log_level':
					if ( ! in_array( $value, array( 'debug', 'info', 'warning', 'error' ), true ) ) {
						$errors[ $key ] = 'invalid';
						break;
					}
					$current[ $key ] = $value;
					break;
				case 'default_view_timezone':
					if ( '' !== $value && ! in_array( $value, timezone_identifiers_list(), true ) ) {
						$errors[ $key ] = 'invalid';
						break;
					}
					$current[ $key ] = (string) $value;
					break;
			}
		}
		if ( array() === $errors ) {
			update_option( self::OPTION, $current, false );
		}
		return $errors;
	}

	/** Site time zone as IANA identifier (WordPress may store a manual UTC offset). */
	public static function site_timezone(): string {
		$configured = (string) self::get( 'default_view_timezone' );
		if ( '' !== $configured ) {
			return $configured;
		}
		$name = wp_timezone_string();
		return in_array( $name, timezone_identifiers_list(), true ) ? $name : 'UTC';
	}
}
