<?php
/**
 * Public read API: events, rendered views, event details and attachment delivery.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Rest;

use SW\Calendar\Application\Render\RenderResult;
use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Presentation\Admin\ConnectionManager;
use SW\Calendar\Presentation\Frontend\ViewLocale;

/**
 * The permission callbacks of these routes are open on purpose: authorization happens per SOURCE inside the
 * read services on every request (SPECIFICATIONS.md §9). Unknown, disabled and unauthorized resources look the same
 * (404, or an empty result for views), so nothing is disclosed through status codes or counts.
 */
final class PublicController extends BaseController {

	private const FILTER_PARAMS = array( 'source', 'view', 'limit', 'days', 'start', 'end', 'past', 'search', 'location', 'category', 'exclude', 'sort', 'template', 'show_images', 'show_attachments', 'month', 'page', 'per_page', 'navigation' );

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/views/(?P<id>\d+)/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'events' ),
				'permission_callback' => '__return_true',
				'args'                => $this->id_arg(),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/views/(?P<id>\d+)/render',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'render' ),
				'permission_callback' => '__return_true',
				'args'                => $this->id_arg() + array(
					'page_url' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/events/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'event' ),
				'permission_callback' => '__return_true',
				'args'                => $this->id_arg() + array(
					'view'     => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'page_url' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
		foreach ( array( 'content', 'preview' ) as $variant ) {
			register_rest_route(
				self::NAMESPACE,
				'/attachments/(?P<id>\d+)/' . $variant,
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'preview' === $variant ? 'attachment_preview' : 'attachment_content' ),
					'permission_callback' => '__return_true',
					'args'                => $this->id_arg(),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/oauth/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'oauth_callback' ),
				// Authorized by the single-use state; the code is only exchanged in an authenticated admin request.
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/editor/views',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'editor_views' ),
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
			)
		);
	}

	public function events( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->rate_limited() ) {
			return $this->too_many();
		}
		$result = ViewLocale::run( $this->services, ViewLocale::for_view( $this->services, (int) $request['id'] ), fn (): array => $this->services->renderer()->events( (int) $request['id'], $this->filters( $request ), $this->current_user() ) );
		if ( RenderResult::NOT_FOUND === $result['status'] ) {
			return $this->error( 'swc_not_found', __( 'Calendar not found.', 'sw-cloudcalendar' ), 404 );
		}
		if ( RenderResult::INVALID === $result['status'] ) {
			return $this->error( 'swc_invalid_filter', __( 'Invalid filter parameters.', 'sw-cloudcalendar' ), 400, $result['errors'] ?? array() );
		}
		$public = (bool) ( $result['public'] ?? false );
		unset( $result['status'], $result['public'] );
		return $this->cache_headers( new \WP_REST_Response( $result ), $public );
	}

	public function render( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->rate_limited() ) {
			return $this->too_many();
		}
		$this->use_page_url( $request );
		$result = ViewLocale::run( $this->services, ViewLocale::for_view( $this->services, (int) $request['id'] ), fn (): RenderResult => $this->services->renderer()->render( (int) $request['id'], $this->filters( $request ), $this->current_user(), true ) );
		if ( RenderResult::NOT_FOUND === $result->status ) {
			return $this->error( 'swc_not_found', __( 'Calendar not found.', 'sw-cloudcalendar' ), 404 );
		}
		if ( RenderResult::INVALID === $result->status ) {
			return $this->error( 'swc_invalid_filter', __( 'Invalid filter parameters.', 'sw-cloudcalendar' ), 400, $result->errors );
		}
		return $this->cache_headers(
			new \WP_REST_Response(
				array(
					'html'  => $result->html,
					'css'   => $result->css,
					'total' => $result->total,
				)
			),
			$result->public
		);
	}

	public function event( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $this->rate_limited() ) {
			return $this->too_many();
		}
		$this->use_page_url( $request );
		$result = ViewLocale::run( $this->services, ViewLocale::for_view( $this->services, (int) $request['view'] ), fn (): RenderResult => $this->services->renderer()->renderDetail( (int) $request['view'], (int) $request['id'], $this->current_user() ) );
		if ( RenderResult::OK !== $result->status ) {
			return $this->error( 'swc_not_found', __( 'Event not found.', 'sw-cloudcalendar' ), 404 );
		}
		return $this->cache_headers(
			new \WP_REST_Response(
				array(
					'html' => $result->html,
					'css'  => $result->css,
				)
			),
			$result->public
		);
	}

	public function attachment_content( \WP_REST_Request $request ): \WP_Error {
		return $this->deliver( (int) $request['id'], false );
	}

	public function attachment_preview( \WP_REST_Request $request ): \WP_Error {
		return $this->deliver( (int) $request['id'], true );
	}

	public function oauth_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$manager  = new ConnectionManager( $this->services );
		$location = $manager->accept_callback(
			(string) $request->get_param( 'state' ),
			(string) $request->get_param( 'code' ),
			(string) $request->get_param( 'error' )
		);
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', esc_url_raw( $location ) );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Referrer-Policy', 'no-referrer' );
		return $response;
	}

	public function editor_views(): \WP_REST_Response {
		$items = array();
		foreach ( $this->services->views()->all() as $view ) {
			$items[] = array(
				'id'           => $view->id,
				'name'         => $view->name,
				'enabled'      => $view->enabled,
				'display_mode' => $view->displayMode->value,
			);
		}
		$response = new \WP_REST_Response( $items );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * Re-checks everything that permits delivery, on every request: stored state, source ACL for the current user,
	 * current source policy and publication state. Returns null when the file must not be delivered.
	 *
	 * @return array{attachment: \SW\Calendar\Domain\Attachment\Attachment, source: \SW\Calendar\Domain\Source\Source, key: string}|null
	 */
	public function authorize_attachment( int $id, bool $preview, ?int $user_id ): ?array {
		$attachment = $this->services->attachments()->find( $id );
		if ( null === $attachment || ! $attachment->isDeliverable() ) {
			return null;
		}
		$source = $this->services->sources()->find( $attachment->sourceId );
		if ( null === $source || ! $this->services->access()->canReadSource( $source->id, $user_id ) ) {
			return null;
		}
		if ( ! $source->settings->attachments->permits( $attachment->publicationState, $source->visibility ) ) {
			return null;
		}
		$key = $preview ? ( $attachment->previewKey ?? $attachment->storageKey ) : $attachment->storageKey;
		return null === $key ? null : array(
			'attachment' => $attachment,
			'source'     => $source,
			'key'        => $key,
		);
	}

	/**
	 * Streams a stored attachment after the authorization above.
	 */
	private function deliver( int $id, bool $preview ): \WP_Error {
		$not_found = $this->error( 'swc_not_found', __( 'File not found.', 'sw-cloudcalendar' ), 404 );
		if ( $this->rate_limited() ) {
			return $this->too_many();
		}
		$granted = $this->authorize_attachment( $id, $preview, $this->current_user() );
		if ( null === $granted ) {
			return $not_found;
		}
		$attachment = $granted['attachment'];
		$source     = $granted['source'];
		try {
			$stream = $this->services->storage()->openReadStream( $granted['key'] );
		} catch ( \Throwable $e ) {
			return $not_found;
		}
		$stat   = fstat( $stream );
		$size   = is_array( $stat ) ? (int) $stat['size'] : 0;
		$inline = $attachment->isImage && \SW\Calendar\Application\Attachments\MimePolicy::isRasterImage( $attachment->mime );
		$name   = $attachment->filename;
		$ascii  = (string) preg_replace( '/[^A-Za-z0-9._\-() ]/', '_', $name );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: ' . ( $inline ? $attachment->mime : ( '' !== $attachment->mime ? $attachment->mime : 'application/octet-stream' ) ) );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'none'; sandbox" );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		if ( Visibility::Public === $source->visibility ) {
			// Short lived so that a later revocation takes effect quickly (no immutable download URLs).
			header( 'Cache-Control: public, max-age=300' );
		} else {
			header( 'Cache-Control: private, no-store, max-age=0' );
			header( 'Vary: Cookie' );
		}
		fpassthru( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit; // phpcs:ignore -- the body was streamed; the REST server must not append JSON.
	}

	/**
	 * @return array<string, mixed>
	 */
	private function filters( \WP_REST_Request $request ): array {
		$filters = array();
		foreach ( $request->get_query_params() as $key => $value ) {
			if ( in_array( $key, self::FILTER_PARAMS, true ) && is_scalar( $value ) ) {
				$filters[ $key ] = (string) $value;
			}
		}
		return $filters;
	}

	/**
	 * Detail and month links of dynamically loaded views point back to the embedding page (same host only).
	 */
	private function use_page_url( \WP_REST_Request $request ): void {
		$url = (string) $request->get_param( 'page_url' );
		if ( '' === $url ) {
			return;
		}
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$this->services->urls()->set_base_url( remove_query_arg( array( 'swc_event', 'swc_view', 'swc_month', 'swc_page' ), $url ) );
		}
	}
}
