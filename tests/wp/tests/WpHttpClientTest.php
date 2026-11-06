<?php

use SW\Calendar\Application\Http\HttpRequest;
use SW\Calendar\Application\Security\UrlGuard;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Infrastructure\Http\WpHttpClient;

/**
 * WordPress HTTP adapter: SSRF guard per hop, manual redirects, credential stripping and size limits.
 * Responses come from the pre_http_request filter; DNS is replaced by an injected resolver.
 */
final class WpHttpClientTest extends SWC_TestCase {

	/** @var list<array{url: string, args: array<string, mixed>}> */
	private array $requests = array();
	/** @var list<array<string, mixed>|WP_Error|callable> */
	private array $responses = array();
	/** @var list<string> */
	private array $resolved = array();

	public function set_up(): void {
		parent::set_up();
		$this->requests  = array();
		$this->responses = array();
		$this->resolved  = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		parent::tear_down();
	}

	/**
	 * @param false|array<string, mixed> $pre
	 * @param array<string, mixed>       $args
	 * @return array<string, mixed>|WP_Error
	 */
	public function intercept( $pre, array $args, string $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		$next = array_shift( $this->responses );
		if ( is_callable( $next ) ) {
			return $next( $args );
		}
		return $next ?? new WP_Error( 'http_request_failed', 'no response queued' );
	}

	/**
	 * @param array<string, string|list<string>> $headers
	 * @return array<string, mixed>
	 */
	private static function response( int $status, string $body = '', array $headers = array() ): array {
		return array(
			// Plain array with lower-case names. Known defect: real transports return a CaseInsensitiveDictionary,
			// which WpHttpClient::to_response() casts with (array) and thereby loses every header.
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function client(): WpHttpClient {
		return new WpHttpClient(
			new UrlGuard(
				function ( string $host ): array {
					$this->resolved[] = $host;
					return array( '93.184.216.34' );
				}
			)
		);
	}

	public function test_get_returns_status_lowercase_headers_and_body(): void {
		$this->responses[] = self::response(
			200,
			'BEGIN:VCALENDAR',
			array(
				'ETag'         => '"v1"',
				'Content-Type' => 'text/calendar',
			)
		);
		$response = $this->client()->send( HttpRequest::get( 'https://calendar.example.org/feed.ics', array( 'Authorization' => 'Bearer x' ) ) );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'BEGIN:VCALENDAR', $response->body );
		$this->assertSame( 15, $response->bytes );
		$this->assertSame( '"v1"', $response->header( 'etag' ) );
		$this->assertSame( 'text/calendar', $response->headers['content-type'] );
		$this->assertSame( array( 'calendar.example.org' ), $this->resolved );

		$args = $this->requests[0]['args'];
		$this->assertSame( 'GET', $args['method'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 10485761, $args['limit_response_size'] );
		$this->assertStringStartsWith( 'SW-CloudCalendar/', $args['user-agent'] );
		$this->assertSame( 'Bearer x', $args['headers']['Authorization'] );
		$this->assertNull( $args['body'] );
		$this->assertFalse( has_action( 'http_api_curl' ), 'DNS pin hook must be removed after the request' );
	}

	public function test_repeated_location_headers_follow_the_first_one(): void {
		// wp_remote_retrieve_header() returns a list when the server sends Location twice; (string) of it was "Array".
		$this->responses[] = self::response( 302, '', array( 'location' => array( 'https://cdn.example.net/first', 'https://cdn.example.net/second' ) ) );
		$this->responses[] = self::response( 200, 'done' );

		$response = $this->client()->send( new HttpRequest( 'GET', 'https://api.example.org/start' ) );

		$this->assertSame( 'done', $response->body );
		$this->assertSame( 'https://cdn.example.net/first', $this->requests[1]['url'] );
	}

	public function test_redirect_to_other_host_drops_credentials_and_post_becomes_get(): void {
		$this->responses[] = self::response( 302, '', array( 'location' => 'https://cdn.example.net/next' ) );
		$this->responses[] = self::response( 200, 'done' );
		$request           = new HttpRequest( 'POST', 'https://api.example.org/start', array( 'Authorization' => 'Bearer secret' ), 'a=1' );

		$response = $this->client()->send( $request );

		$this->assertSame( 'done', $response->body );
		$this->assertSame( array( 'api.example.org', 'cdn.example.net' ), $this->resolved );
		$this->assertSame( 'a=1', $this->requests[0]['args']['body'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( 'https://cdn.example.net/next', $this->requests[1]['url'] );
		$this->assertSame( 'GET', $this->requests[1]['args']['method'] );
		$this->assertNull( $this->requests[1]['args']['body'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[1]['args']['headers'] );
	}

	public function test_same_host_relative_redirect_keeps_credentials_and_method(): void {
		$this->responses[] = self::response( 307, '', array( 'location' => '/v2/feed' ) );
		$this->responses[] = self::response( 200, 'ok' );
		$this->client()->send( new HttpRequest( 'PUT', 'https://api.example.org/v1/feed', array( 'Authorization' => 'Bearer s' ), 'x' ) );

		$this->assertSame( 'https://api.example.org/v2/feed', $this->requests[1]['url'] );
		$this->assertSame( 'PUT', $this->requests[1]['args']['method'] );
		$this->assertSame( 'x', $this->requests[1]['args']['body'] );
		$this->assertSame( 'Bearer s', $this->requests[1]['args']['headers']['Authorization'] );
	}

	public function test_303_switches_any_method_to_get(): void {
		$this->responses[] = self::response( 303, '', array( 'location' => 'https://api.example.org/result' ) );
		$this->responses[] = self::response( 200, 'r' );
		$this->client()->send( new HttpRequest( 'PATCH', 'https://api.example.org/job', array(), 'b' ) );
		$this->assertSame( 'GET', $this->requests[1]['args']['method'] );
	}

	public function test_redirect_without_location_fails_and_non_redirect_3xx_is_returned(): void {
		$this->responses[] = self::response( 304 );
		$this->assertSame( 304, $this->client()->send( HttpRequest::get( 'https://a.example.org/' ) )->status );

		$this->responses[] = self::response( 302 );
		try {
			$this->client()->send( HttpRequest::get( 'https://a.example.org/' ) );
			$this->fail( 'Expected exception' );
		} catch ( CalendarException $e ) {
			$this->assertSame( ErrorCode::FetchFailed, $e->errorCode );
		}
		$this->assertCount( 2, $this->requests, 'A redirect without Location is not followed' );
	}

	public function test_too_many_redirects_fail(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->responses[] = self::response( 301, '', array( 'location' => 'https://a.example.org/' . $i ) );
		}
		$request = new HttpRequest( 'GET', 'https://a.example.org/', array(), null, 20, 1000, null, null, false, 2 );
		try {
			$this->client()->send( $request );
			$this->fail( 'Expected exception' );
		} catch ( CalendarException $e ) {
			$this->assertSame( ErrorCode::FetchFailed, $e->errorCode );
			$this->assertStringContainsString( 'Too many redirects', $e->getMessage() );
		}
		$this->assertCount( 3, $this->requests );
	}

	public function test_redirect_to_private_address_is_blocked_by_the_guard(): void {
		$this->responses[] = self::response( 302, '', array( 'location' => 'https://127.0.0.1/admin' ) );
		$this->expectException( CalendarException::class );
		try {
			$this->client()->send( HttpRequest::get( 'https://a.example.org/' ) );
		} finally {
			$this->assertCount( 1, $this->requests, 'The internal target must never be requested' );
		}
	}

	public function test_transport_errors_are_redacted_fetch_failures(): void {
		$this->responses[] = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
		try {
			$this->client()->send( HttpRequest::get( 'https://a.example.org/' ) );
			$this->fail( 'Expected exception' );
		} catch ( CalendarException $e ) {
			$this->assertSame( ErrorCode::FetchFailed, $e->errorCode );
			$this->assertStringContainsString( 'cURL error 28', $e->getMessage() );
		}
	}

	public function test_oversized_body_is_an_error_not_a_truncation(): void {
		$this->responses[] = self::response( 200, str_repeat( 'x', 11 ) );
		$this->expectException( CalendarException::class );
		$this->client()->send( new HttpRequest( 'GET', 'https://a.example.org/', array(), null, 20, 10 ) );
	}

	public function test_sink_downloads_report_size_and_enforce_limit(): void {
		$file              = wp_tempnam( 'swc-sink' );
		$this->responses[] = static function ( array $args ): array {
			file_put_contents( $args['filename'], '12345' ); // phpcs:ignore
			return self::response( 200, '', array( 'Content-Type' => array( 'a', 'image/png' ) ) );
		};
		$response = $this->client()->send( HttpRequest::get( 'https://a.example.org/f.png' )->withSink( $file, 5 ) );
		$this->assertSame( 5, $response->bytes );
		$this->assertSame( '', $response->body );
		$this->assertSame( 'image/png', $response->header( 'content-type' ) );
		$this->assertTrue( $this->requests[0]['args']['stream'] );
		$this->assertSame( $file, $this->requests[0]['args']['filename'] );
		$this->assertSame( 60, $this->requests[0]['args']['timeout'] );

		$this->responses[] = static function ( array $args ): array {
			file_put_contents( $args['filename'], '123456' ); // phpcs:ignore
			return self::response( 200 );
		};
		try {
			$this->client()->send( HttpRequest::get( 'https://a.example.org/f.png' )->withSink( $file, 5 ) );
			$this->fail( 'Expected exception' );
		} catch ( CalendarException $e ) {
			$this->assertSame( ErrorCode::DownloadTooLarge, $e->errorCode );
		}
		$this->assertFileDoesNotExist( $file );
	}

	public function test_sink_is_removed_on_redirect_and_transport_error(): void {
		$file = wp_tempnam( 'swc-sink' );
		file_put_contents( $file, 'partial' ); // phpcs:ignore
		$this->responses[] = self::response( 302, '', array( 'location' => 'https://a.example.org/b' ) );
		$this->responses[] = static function ( array $args ): WP_Error {
			file_put_contents( $args['filename'], 'half' ); // phpcs:ignore
			return new WP_Error( 'http_request_failed', 'reset' );
		};
		try {
			$this->client()->send( HttpRequest::get( 'https://a.example.org/a' )->withSink( $file, 100 ) );
			$this->fail( 'Expected exception' );
		} catch ( CalendarException $e ) {
			$this->assertSame( ErrorCode::FetchFailed, $e->errorCode );
		}
		$this->assertCount( 2, $this->requests );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_missing_sink_file_counts_as_empty_download(): void {
		$file = wp_tempnam( 'swc-sink' );
		wp_delete_file( $file );
		$this->responses[] = self::response( 204 );
		$this->assertSame( 0, $this->client()->send( HttpRequest::get( 'https://a.example.org/' )->withSink( $file, 10 ) )->bytes );
	}

	public function test_default_guard_rejects_plain_http(): void {
		$this->expectException( CalendarException::class );
		( new WpHttpClient() )->send( HttpRequest::get( 'http://a.example.org/' ) );
	}
}
