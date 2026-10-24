<?php
/**
 * Admin API: diagnostics, settings, caches, logs, audit, sync history and metadata for the admin UI.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Rest;

use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Source\AttachmentPolicy;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Infrastructure\WordPress\QueueRunner;
use SW\Calendar\Infrastructure\WordPress\Settings;
use SW\Calendar\Presentation\Admin\ConnectionManager;
use SW\Calendar\Presentation\Admin\Diagnostics;

final class SystemController extends BaseController {

	public function register_routes(): void {
		$admin  = array( $this, 'can_manage' );
		$routes = array(
			'/admin/diagnostics'               => array( 'GET', 'diagnostics' ),
			'/admin/diagnostics/storage-check' => array( 'POST', 'storage_check' ),
			'/admin/cache/clear'               => array( 'POST', 'clear_cache' ),
			'/admin/logs'                      => array( 'GET', 'logs' ),
			'/admin/audit'                     => array( 'GET', 'audit' ),
			'/admin/sync/runs'                 => array( 'GET', 'runs' ),
			'/admin/sync/schedule'             => array( 'POST', 'schedule' ),
			'/admin/meta'                      => array( 'GET', 'meta' ),
		);
		foreach ( $routes as $path => [ $method, $callback ] ) {
			register_rest_route(
				self::NAMESPACE,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $admin,
					'args'                => 'GET' === $method ? $this->pagination_args() : array(),
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/admin/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'settings' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => $admin,
				),
			)
		);
	}

	public function diagnostics(): \WP_REST_Response {
		$response = new \WP_REST_Response( ( new Diagnostics( $this->services ) )->collect() );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public function storage_check(): \WP_REST_Response {
		return new \WP_REST_Response( $this->services->storage_guard()->verify() );
	}

	/**
	 * Clears template AST and rendered HTML caches. Both live under one generation counter, so every scope clears
	 * both (documented in docs/rest-api.md).
	 */
	public function clear_cache( \WP_REST_Request $request ): \WP_REST_Response {
		$this->services->cache()->clear();
		$this->services->audit()->record( 'cache_cleared', 'system', 0, array( 'scope' => sanitize_key( (string) ( $this->body( $request )['scope'] ?? 'all' ) ) ) );
		return new \WP_REST_Response( array( 'cleared' => true ) );
	}

	public function logs( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];
		$level    = $request->get_param( 'level' );
		$source   = $request->get_param( 'source_id' );
		$items    = $this->services->logger()->recent( $per_page + 1, ( $page - 1 ) * $per_page, is_string( $level ) && '' !== $level ? $level : null, is_numeric( $source ) ? (int) $source : null );
		$more     = count( $items ) > $per_page;
		return $this->collection( array_slice( $items, 0, $per_page ), ( $page - 1 ) * $per_page + count( $items ), $page, $per_page + ( $more ? 1 : 0 ) );
	}

	public function audit( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];
		$items    = $this->services->audit()->recent( $per_page, ( $page - 1 ) * $per_page );
		foreach ( $items as &$item ) {
			$user              = get_userdata( (int) $item['user_id'] );
			$item['user_name'] = $user instanceof \WP_User ? $user->display_name : null;
		}
		unset( $item );
		return $this->collection( $items, ( $page - 1 ) * $per_page + count( $items ) + ( count( $items ) === $per_page ? 1 : 0 ), $page, $per_page );
	}

	public function runs( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = (int) $request['page'];
		$per_page = (int) $request['per_page'];
		return $this->collection(
			$this->services->sync_runs()->recent( null, $per_page, ( $page - 1 ) * $per_page ),
			$this->services->sync_runs()->count( null ),
			$page,
			$per_page
		);
	}

	/** Queues jobs for all due sources and triggers WP-Cron; never runs a sync inside this request. */
	public function schedule(): \WP_REST_Response {
		$count = ( new QueueRunner( $this->services ) )->schedule_due();
		if ( 'wp' === Settings::get( 'cron_mode' ) ) {
			spawn_cron();
		}
		return new \WP_REST_Response( array( 'queued' => $count ), 202 );
	}

	public function settings(): \WP_REST_Response {
		return new \WP_REST_Response( Settings::all() );
	}

	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$errors = Settings::update( $this->body( $request ) );
		if ( array() !== $errors ) {
			return $this->error( 'swc_invalid', __( 'Please check the highlighted fields.', 'sw-cloudcalendar' ), 400, $errors );
		}
		$this->services->cache()->clear();
		$this->services->audit()->record( 'settings_updated', 'system', 0 );
		return new \WP_REST_Response( Settings::all() );
	}

	public function meta(): \WP_REST_Response {
		$auth_modes = array();
		foreach ( Provider::cases() as $provider ) {
			$auth_modes[ $provider->value ] = array_map( static fn ( AuthMode $mode ): string => $mode->value, AuthMode::allowedFor( $provider ) );
		}
		return new \WP_REST_Response(
			array(
				'roles'                => wp_roles()->get_names(),
				'auth_modes'           => $auth_modes,
				'supported_mime_types' => AttachmentPolicy::SUPPORTED_MIME_TYPES,
				'default_mime_types'   => AttachmentPolicy::DEFAULT_MIME_TYPES,
				'event_types'          => SourceSettings::KNOWN_EVENT_TYPES,
				'max_window_days'      => $this->services->max_window_days(),
				'redirect_uri'         => ConnectionManager::redirect_uri(),
				'storage_safe'         => $this->services->storage_guard()->isSafe(),
				'site_timezone'        => Settings::site_timezone(),
				'timezones'            => timezone_identifiers_list(),
				'date_format'          => get_option( 'date_format' ),
				'time_format'          => get_option( 'time_format' ),
				'encryption_fallback'  => $this->services->vault()->usesFallback,
			)
		);
	}
}
