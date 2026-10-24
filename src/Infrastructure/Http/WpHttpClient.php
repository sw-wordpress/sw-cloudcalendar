<?php
/**
 * WordPress HTTP API client with SSRF protection.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Http;

use SW\Calendar\Application\Http\HttpRequest;
use SW\Calendar\Application\Http\HttpResponse;
use SW\Calendar\Application\Port\HttpClientInterface;
use SW\Calendar\Application\Security\UrlGuard;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Support\Redactor;

/**
 * Every hop (initial URL and each redirect) is validated by UrlGuard; redirects are followed manually. The vetted IP is
 * pinned for cURL (CURLOPT_RESOLVE) so a second DNS answer cannot redirect the request to an internal address.
 * Response bodies are limited to `maxBytes`; an exceeded limit is an error, never a silently truncated file.
 */
final class WpHttpClient implements HttpClientInterface {

	public function __construct( private readonly UrlGuard $guard = new UrlGuard() ) {
	}

	public function send( HttpRequest $request ): HttpResponse {
		$url     = $request->url;
		$method  = $request->method;
		$body    = $request->body;
		$headers = $request->headers;
		for ( $hop = 0; $hop <= $request->maxRedirects; $hop++ ) {
			$ips      = $this->guard->check( $url, $request->allowHttp, $request->allowedHosts );
			$response = $this->request( $request, $url, $method, $headers, $body, $ips[0] );
			$status   = (int) wp_remote_retrieve_response_code( $response );
			if ( $status >= 300 && $status < 400 && in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( '' === $location ) {
					break;
				}
				if ( null !== $request->sinkFile && is_file( $request->sinkFile ) ) {
					wp_delete_file( $request->sinkFile );
				}
				$next = \WP_Http::make_absolute_url( $location, $url );
				// Credentials are only sent to the original host.
				if ( wp_parse_url( $next, PHP_URL_HOST ) !== wp_parse_url( $url, PHP_URL_HOST ) ) {
					unset( $headers['Authorization'], $headers['authorization'] );
				}
				if ( 303 === $status || ( in_array( $status, array( 301, 302 ), true ) && 'POST' === $method ) ) {
					$method = 'GET';
					$body   = null;
				}
				$url = $next;
				continue;
			}
			return $this->to_response( $request, $response, $status );
		}
		throw new CalendarException( ErrorCode::FetchFailed, 'Too many redirects.' );
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	private function request( HttpRequest $request, string $url, string $method, array $headers, ?string $body, string $ip ): array {
		$args = array(
			'method'              => $method,
			'headers'             => $headers,
			'timeout'             => $request->timeout,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => $request->maxBytes + 1,
			'user-agent'          => 'SW-CloudCalendar/' . ( defined( 'SW_CALENDAR_VERSION' ) ? SW_CALENDAR_VERSION : '1' ) . '; ' . home_url( '/' ),
			'sslverify'           => true,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		if ( null !== $request->sinkFile ) {
			$args['stream']   = true;
			$args['filename'] = $request->sinkFile;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port = (int) ( wp_parse_url( $url, PHP_URL_PORT ) ?: ( str_starts_with( strtolower( $url ), 'https:' ) ? 443 : 80 ) );
		$pin  = static function ( $handle ) use ( $host, $port, $ip ): void {
			if ( defined( 'CURLOPT_RESOLVE' ) ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- DNS pinning has no WP API.
			}
		};
		add_action( 'http_api_curl', $pin, 10, 1 );
		try {
			$response = wp_safe_remote_request( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $pin, 10 );
		}
		if ( is_wp_error( $response ) ) {
			if ( null !== $request->sinkFile && is_file( $request->sinkFile ) ) {
				wp_delete_file( $request->sinkFile );
			}
			throw new CalendarException( ErrorCode::FetchFailed, 'HTTP request failed: ' . Redactor::text( $response->get_error_message(), 160 ) );
		}
		return $response;
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function to_response( HttpRequest $request, array $response, int $status ): HttpResponse {
		$headers = array();
		foreach ( (array) wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}
		if ( null !== $request->sinkFile ) {
			clearstatcache( true, $request->sinkFile );
			$bytes = is_file( $request->sinkFile ) ? (int) filesize( $request->sinkFile ) : 0;
			if ( $bytes > $request->maxBytes ) {
				wp_delete_file( $request->sinkFile );
				throw new CalendarException( ErrorCode::DownloadTooLarge, 'The download exceeds the size limit.' );
			}
			return new HttpResponse( $status, $headers, '', $bytes );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > $request->maxBytes ) {
			throw new CalendarException( ErrorCode::DownloadTooLarge, 'The response exceeds the size limit.' );
		}
		return new HttpResponse( $status, $headers, $body, strlen( $body ) );
	}
}
