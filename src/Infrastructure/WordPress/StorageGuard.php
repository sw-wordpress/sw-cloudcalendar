<?php
/**
 * Locates the private storage and verifies that it is not reachable from the web (SPECIFICATIONS.md §8.4).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

use SW\Calendar\Application\Port\StorageGuardInterface;
use SW\Calendar\Infrastructure\Storage\PrivateFileStorage;

/**
 * Order: `SW_CALENDAR_PRIVATE_STORAGE_PATH` (recommended, outside the document root) or a random directory below
 * the uploads folder. A location inside the web root is only accepted after an HTTP probe proved that a file in it
 * cannot be fetched; `.htaccess` alone does not protect on Nginx. If nothing can be verified, attachment sync stays
 * disabled (fail closed).
 */
final class StorageGuard implements StorageGuardInterface {

	public const OPTION     = 'sw_calendar_storage';
	public const CHECK_TTL  = DAY_IN_SECONDS;
	private const PROBE_TTL = 6 * HOUR_IN_SECONDS;

	private ?array $state = null;

	public function root(): string {
		if ( defined( 'SW_CALENDAR_PRIVATE_STORAGE_PATH' ) && '' !== (string) SW_CALENDAR_PRIVATE_STORAGE_PATH ) {
			return rtrim( (string) SW_CALENDAR_PRIVATE_STORAGE_PATH, '/' );
		}
		$state = $this->state();
		if ( isset( $state['fallback'] ) && is_string( $state['fallback'] ) && '' !== $state['fallback'] ) {
			return $state['fallback'];
		}
		$uploads  = wp_upload_dir( null, false );
		$fallback = rtrim( (string) $uploads['basedir'], '/' ) . '/sw-cloudcalendar-private-' . bin2hex( random_bytes( 8 ) );
		$this->save( array( 'fallback' => $fallback ) );
		return $fallback;
	}

	public function storage(): PrivateFileStorage {
		return new PrivateFileStorage( $this->root() );
	}

	public function isSafe(): bool {
		$state = $this->state();
		if ( ! isset( $state['checked_at'] ) || (int) $state['checked_at'] < time() - self::PROBE_TTL || ( $state['root'] ?? '' ) !== $this->root() ) {
			$state = $this->verify();
		}
		return ! empty( $state['safe'] );
	}

	public function reason(): string {
		return (string) ( $this->state()['reason'] ?? '' );
	}

	/**
	 * Runs the checks now and stores the result.
	 *
	 * @return array<string, mixed>
	 */
	public function verify(): array {
		$root    = $this->root();
		$storage = new PrivateFileStorage( $root );
		$result  = array(
			'root'       => $root,
			'checked_at' => time(),
			'safe'       => false,
			'method'     => '',
			'reason'     => '',
		);
		try {
			$storage->prepare();
		} catch ( \Throwable $e ) {
			$result['reason'] = 'not_writable';
			$this->save( $result );
			return $result;
		}
		$real = realpath( $root );
		if ( false === $real || ! is_writable( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			$result['reason'] = 'not_writable';
			$this->save( $result );
			return $result;
		}
		if ( ! $this->inside_web_root( $real ) ) {
			$result['safe']   = true;
			$result['method'] = 'outside_document_root';
			$this->save( $result );
			return $result;
		}
		$probe            = $this->http_probe( $real );
		$result['safe']   = 'blocked' === $probe;
		$result['method'] = 'http_probe';
		$result['reason'] = 'blocked' === $probe ? '' : $probe;
		$this->save( $result );
		return $result;
	}

	private function inside_web_root( string $real ): bool {
		$roots = array( realpath( ABSPATH ) );
		if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) {
			$roots[] = realpath( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) );
		}
		$roots[] = realpath( WP_CONTENT_DIR );
		foreach ( array_filter( $roots ) as $web_root ) {
			if ( str_starts_with( $real . '/', rtrim( (string) $web_root, '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Writes a random probe file and requests it through the web server.
	 *
	 * @return string `blocked` when the server refused to serve it, otherwise the failure reason.
	 */
	private function http_probe( string $real ): string {
		$uploads = wp_upload_dir( null, false );
		$basedir = rtrim( (string) realpath( (string) $uploads['basedir'] ), '/' );
		if ( '' === $basedir || ! str_starts_with( $real . '/', $basedir . '/' ) ) {
			return 'unverifiable_location';
		}
		$name    = 'probe-' . bin2hex( random_bytes( 12 ) ) . '.txt';
		$content = 'swc-probe-' . bin2hex( random_bytes( 16 ) );
		$file    = $real . '/' . $name;
		if ( false === file_put_contents( $file, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return 'not_writable';
		}
		$url = rtrim( (string) $uploads['baseurl'], '/' ) . substr( $real, strlen( $basedir ) ) . '/' . $name;
		try {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core loopback filter.
				)
			);
		} finally {
			wp_delete_file( $file );
		}
		if ( is_wp_error( $response ) ) {
			// Loopback requests are blocked: protection cannot be verified, so it is treated as unsafe.
			return 'probe_failed';
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( str_contains( (string) wp_remote_retrieve_body( $response ), $content ) ) {
			return 'publicly_accessible';
		}
		return in_array( $status, array( 401, 403, 404 ), true ) ? 'blocked' : 'probe_inconclusive_' . $status;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(): array {
		if ( null === $this->state ) {
			$value       = get_option( self::OPTION, array() );
			$this->state = is_array( $value ) ? $value : array();
		}
		return $this->state;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function save( array $values ): void {
		$this->state = array_merge( $this->state(), $values );
		update_option( self::OPTION, $this->state, false );
	}
}
