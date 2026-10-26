<?php
/**
 * Admin API: calendar views.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Rest;

use SW\Calendar\Application\Render\RenderResult;
use SW\Calendar\Presentation\Admin\ViewManager;
use SW\Calendar\Presentation\Frontend\ViewLocale;

final class ViewsController extends BaseController {

	public function register_routes(): void {
		$admin = array( $this, 'can_manage' );
		register_rest_route(
			self::NAMESPACE,
			'/admin/views',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/views/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/views/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $admin,
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $admin,
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $admin,
					'args'                => $this->id_arg(),
				),
			)
		);
	}

	/**
	 * Live preview of an unsaved (or disabled) view body. Uses the ACL of the current administrator; never cached.
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$body    = $this->body( $request );
			$id      = isset( $body['id'] ) && (int) $body['id'] > 0 ? (int) $body['id'] : null;
			$view    = $this->manager()->unsaved( $id, is_array( $body['view'] ?? null ) ? $body['view'] : array() );
			$filters = is_array( $body['filters'] ?? null ) ? $body['filters'] : array();
			$result  = ViewLocale::run( $this->services, $view->language, fn () => $this->services->renderer()->renderView( $view, $filters, $this->current_user(), true ) );
			if ( RenderResult::INVALID === $result->status ) {
				return $this->error( 'swc_invalid_filter', __( 'Invalid filter parameters.', 'sw-cloudcalendar' ), 400, $result->errors );
			}
			$response = new \WP_REST_Response(
				array(
					'html'  => $result->html,
					'css'   => (object) $result->css,
					'total' => $result->total,
				)
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}

	private function manager(): ViewManager {
		return new ViewManager( $this->services );
	}

	public function index(): \WP_REST_Response {
		$items = array_map( array( $this->manager(), 'to_array' ), $this->services->views()->all() );
		return $this->collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$view = $this->services->views()->find( (int) $request['id'] );
		if ( null === $view ) {
			return $this->error( 'swc_not_found', __( 'View not found.', 'sw-cloudcalendar' ), 404 );
		}
		return new \WP_REST_Response( $this->manager()->to_array( $view ) );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->manager()->to_array( $this->manager()->save( null, $this->body( $request ) ) ), 201 );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->manager()->to_array( $this->manager()->save( (int) $request['id'], $this->body( $request ) ) ) );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}

	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$this->manager()->delete( (int) $request['id'] );
			return new \WP_REST_Response( array( 'deleted' => true ) );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}
}
