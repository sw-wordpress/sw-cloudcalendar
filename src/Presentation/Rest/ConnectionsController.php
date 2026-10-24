<?php
/**
 * Admin API: connections (secrets are write-only).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Rest;

use SW\Calendar\Presentation\Admin\ConnectionManager;

final class ConnectionsController extends BaseController {

	public function register_routes(): void {
		$admin = array( $this, 'can_manage' );
		register_rest_route(
			self::NAMESPACE,
			'/admin/connections',
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
			'/admin/connections/(?P<id>\d+)',
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
		$actions = array(
			'test'       => array( 'POST', 'test' ),
			'calendars'  => array( 'GET', 'calendars' ),
			'authorize'  => array( 'POST', 'authorize' ),
			'disconnect' => array( 'POST', 'disconnect' ),
		);
		foreach ( $actions as $path => [ $method, $callback ] ) {
			register_rest_route(
				self::NAMESPACE,
				'/admin/connections/(?P<id>\d+)/' . $path,
				array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $admin,
					'args'                => $this->id_arg(),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/admin/oauth/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => $admin,
				'args'                => array(
					'state' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	private function manager(): ConnectionManager {
		return new ConnectionManager( $this->services );
	}

	public function index(): \WP_REST_Response {
		$items = array_map( array( $this->manager(), 'to_array' ), $this->services->connections()->all() );
		return $this->collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$connection = $this->services->connections()->find( (int) $request['id'] );
		if ( null === $connection ) {
			return $this->error( 'swc_not_found', __( 'Connection not found.', 'sw-cloudcalendar' ), 404 );
		}
		return new \WP_REST_Response( $this->manager()->to_array( $connection ) );
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

	public function test( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->manager()->test( (int) $request['id'] ) );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}

	public function calendars( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$items = $this->manager()->calendars( (int) $request['id'] );
			return $this->collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}

	public function authorize( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->manager()->start_authorization( (int) $request['id'] ) );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}

	public function complete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$connection = $this->manager()->complete_authorization( (string) $request['state'], get_current_user_id() );
			return new \WP_REST_Response( $this->manager()->to_array( $connection ) );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}

	public function disconnect( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$this->manager()->disconnect( (int) $request['id'] );
			return new \WP_REST_Response( $this->manager()->to_array( $this->services->connections()->find( (int) $request['id'] ) ) );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}
}
