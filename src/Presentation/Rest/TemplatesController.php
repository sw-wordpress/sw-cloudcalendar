<?php
/**
 * Admin API: templates, presets, validation and preview.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Rest;

use SW\Calendar\Domain\Template\TemplateKind;
use SW\Calendar\Presentation\Admin\TemplateManager;

final class TemplatesController extends BaseController {

	public function register_routes(): void {
		$admin = array( $this, 'can_manage' );
		register_rest_route(
			self::NAMESPACE,
			'/admin/templates',
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
			'/admin/templates/(?P<id>\d+)',
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
		foreach (
			array(
				'validate'  => array( 'POST', 'validate' ),
				'preview'   => array( 'POST', 'preview' ),
				'presets'   => array( 'GET', 'presets' ),
				'reference' => array( 'GET', 'reference' ),
			) as $path => [ $method, $callback ]
		) {
			register_rest_route(
				self::NAMESPACE,
				'/admin/templates/' . $path,
				array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $admin,
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/admin/templates/presets/(?P<key>[a-z0-9-]+)/install',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'install' ),
				'permission_callback' => $admin,
			)
		);
	}

	private function manager(): TemplateManager {
		return new TemplateManager( $this->services );
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$kind  = TemplateKind::tryFrom( (string) $request->get_param( 'kind' ) );
		$items = array_map( fn ( $t ): array => $this->manager()->to_array( $t, false ), $this->services->templates()->all( $kind ) );
		return $this->collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$template = $this->services->templates()->find( (int) $request['id'] );
		if ( null === $template ) {
			return $this->error( 'swc_not_found', __( 'Template not found.', 'sw-cloudcalendar' ), 404 );
		}
		return new \WP_REST_Response( $this->manager()->to_array( $template ) );
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

	public function validate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $this->body( $request );
		$kind = TemplateKind::tryFrom( (string) ( $body['kind'] ?? '' ) );
		if ( null === $kind ) {
			return $this->error( 'swc_invalid', __( 'Unknown template kind.', 'sw-cloudcalendar' ), 400, array( 'kind' => 'invalid' ) );
		}
		return new \WP_REST_Response( $this->manager()->validate( (string) ( $body['body'] ?? '' ), (string) ( $body['css'] ?? '' ), $kind ) );
	}

	public function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$response = new \WP_REST_Response( $this->manager()->preview( $this->body( $request ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public function presets(): \WP_REST_Response {
		return new \WP_REST_Response( $this->manager()->presets() );
	}

	public function reference(): \WP_REST_Response {
		return new \WP_REST_Response( $this->manager()->reference() );
	}

	public function install( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$created = $this->manager()->install_preset( (string) $request['key'] );
			return new \WP_REST_Response( array_map( fn ( $t ): array => $this->manager()->to_array( $t, false ), $created ), 201 );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}
}
