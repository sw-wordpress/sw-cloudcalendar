<?php
/**
 * Private storage verification (SPECIFICATIONS.md §8.4): outside the web root is safe, inside only after an HTTP
 * probe proved the file cannot be fetched (fail closed).
 */

use SW\Calendar\Infrastructure\WordPress\StorageGuard;

final class StorageGuardTest extends SWC_TestCase {

	private string $document_root = '';

	public function set_up(): void {
		parent::set_up();
		$this->document_root = (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' );
		delete_option( StorageGuard::OPTION );
	}

	public function tear_down(): void {
		$_SERVER['DOCUMENT_ROOT'] = $this->document_root;
		parent::tear_down();
	}

	public function test_configured_path_outside_document_root_is_safe_and_cached(): void {
		$guard = new StorageGuard();
		$this->assertSame( rtrim( SW_CALENDAR_PRIVATE_STORAGE_PATH, '/' ), $guard->root() );
		$this->assertTrue( $guard->isSafe() );
		$this->assertSame( '', $guard->reason() );
		$state = get_option( StorageGuard::OPTION );
		$this->assertSame( 'outside_document_root', $state['method'] );

		// A stale or different root triggers a new check; a fresh state is reused.
		update_option( StorageGuard::OPTION, array( 'checked_at' => time(), 'root' => $guard->root(), 'safe' => false, 'reason' => 'cached' ) );
		$cached = new StorageGuard();
		$this->assertFalse( $cached->isSafe() );
		$this->assertSame( 'cached', $cached->reason() );
		update_option( StorageGuard::OPTION, array( 'checked_at' => time(), 'root' => '/elsewhere', 'safe' => false ) );
		$this->assertTrue( ( new StorageGuard() )->isSafe() );
		$this->assertInstanceOf( \SW\Calendar\Infrastructure\Storage\PrivateFileStorage::class, $guard->storage() );
	}

	public function test_storage_below_document_root_outside_uploads_is_unverifiable(): void {
		$_SERVER['DOCUMENT_ROOT'] = dirname( rtrim( SW_CALENDAR_PRIVATE_STORAGE_PATH, '/' ) );
		$result                   = ( new StorageGuard() )->verify();
		$this->assertFalse( $result['safe'] );
		$this->assertSame( 'http_probe', $result['method'] );
		$this->assertSame( 'unverifiable_location', $result['reason'] );
	}

	/**
	 * @return string Result of the private HTTP probe for a directory inside uploads with a fake web server.
	 */
	private function probe( callable $server ): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = rtrim( $uploads['basedir'], '/' ) . '/swc-probe-test';
		wp_mkdir_p( $dir );
		$filter = static function ( $pre, $args, $url ) use ( $server ) {
			return $server( $url );
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );
		try {
			$method = new ReflectionMethod( StorageGuard::class, 'http_probe' );
			$method->setAccessible( true );
			return (string) $method->invoke( new StorageGuard(), (string) realpath( $dir ) );
		} finally {
			remove_filter( 'pre_http_request', $filter, 10 );
			$this->assertSame( array( '.', '..' ), scandir( $dir ), 'Probe file is always removed' );
			rmdir( $dir );
		}
	}

	private static function response( int $status, string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	public function test_http_probe_outcomes(): void {
		$requested = '';
		$this->assertSame(
			'blocked',
			$this->probe(
				static function ( $url ) use ( &$requested ) {
					$requested = $url;
					return self::response( 403, 'Forbidden' );
				}
			)
		);
		$this->assertMatchesRegularExpression( '#/swc-probe-test/probe-[0-9a-f]{24}\.txt$#', $requested );

		$this->assertSame(
			'publicly_accessible',
			$this->probe(
				static function ( $url ) {
					$path = wp_upload_dir( null, false )['basedir'] . substr( $url, strlen( wp_upload_dir( null, false )['baseurl'] ) );
					return self::response( 200, (string) file_get_contents( $path ) );
				}
			)
		);
		$this->assertSame( 'probe_inconclusive_500', $this->probe( static fn () => self::response( 500, 'error' ) ) );
		$this->assertSame( 'probe_failed', $this->probe( static fn () => new WP_Error( 'http_request_failed', 'loopback blocked' ) ) );
	}

	public function test_web_root_detection(): void {
		$method = new ReflectionMethod( StorageGuard::class, 'inside_web_root' );
		$method->setAccessible( true );
		$guard = new StorageGuard();
		$this->assertTrue( $method->invoke( $guard, (string) realpath( WP_CONTENT_DIR ) . '/uploads' ) );
		$_SERVER['DOCUMENT_ROOT'] = '';
		$this->assertFalse( $method->invoke( $guard, (string) realpath( SW_CALENDAR_PRIVATE_STORAGE_PATH ) ) );
	}
}
