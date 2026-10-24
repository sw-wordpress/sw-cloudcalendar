<?php
/**
 * Admin API: sources and manual synchronisation.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Rest;

use SW\Calendar\Presentation\Admin\SourceManager;

final class SourcesController extends BaseController {

	public function register_routes(): void {
		$admin = array( $this, 'can_manage' );
		register_rest_route(
			self::NAMESPACE,
			'/admin/sources',
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
			'/admin/sources/(?P<id>\d+)',
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
		register_rest_route(
			self::NAMESPACE,
			'/admin/sources/(?P<id>\d+)/sync',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sync' ),
				'permission_callback' => $admin,
				'args'                => $this->id_arg(),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/admin/sources/(?P<id>\d+)/runs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'runs' ),
				'permission_callback' => $admin,
				'args'                => $this->id_arg() + $this->pagination_args(),
			)
		);
	}

	private function manager(): SourceManager {
		return new SourceManager( $this->services );
	}

	public function index(): \WP_REST_Response {
		$items = array_map( array( $this->manager(), 'to_array' ), $this->services->sources()->all() );
		return $this->collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$source = $this->services->sources()->find( (int) $request['id'] );
		if ( null === $source ) {
			return $this->error( 'swc_not_found', __( 'Source not found.', 'sw-cloudcalendar' ), 404 );
		}
		return new \WP_REST_Response( $this->manager()->to_array( $source ) );
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

	/** Queues a job and returns immediately (202); the run happens in the background. */
	public function sync( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$body = $this->body( $request );
			$this->manager()->request_sync( (int) $request['id'], ! empty( $body['full'] ) );
			return new \WP_REST_Response( array( 'queued' => true ), 202 );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}

	public function runs( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];
		$source   = (int) $request['id'];
		return $this->collection(
			$this->services->sync_runs()->recent( $source, $per_page, ( $page - 1 ) * $per_page ),
			$this->services->sync_runs()->count( $source ),
			$page,
			$per_page
		);
	}
}
