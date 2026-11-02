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
			'/admin/sources/(?P<id>\d+)/sync-now',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sync_now' ),
				'permission_callback' => $admin,
				'args'                => $this->id_arg(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/admin/sources/(?P<id>\d+)/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'events' ),
				'permission_callback' => $admin,
				'args'                => $this->id_arg() + $this->pagination_args() + array(
					'month' => array(
						'type'    => 'string',
						'pattern' => '^[1-9][0-9]{3}-(0[1-9]|1[0-2])$',
					),
				),
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

	public function sync_now( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$report = $this->manager()->synchronize_now( (int) $request['id'] );
			if ( ! $report->isSuccess() ) {
				return $this->error( 'swc_sync_failed', __( 'Synchronization did not complete. Check the sync history for details.', 'sw-cloudcalendar' ), \SW\Calendar\Application\Sync\SyncReport::FAILED === $report->status ? 502 : 409 );
			}
			return $this->show( $request );
		} catch ( \Throwable $e ) {
			return $this->from_exception( $e );
		}
	}

	/** Admin preview also works for drafts; the adapter's own access check remains mandatory. */
	public function events( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$source     = $this->services->sources()->find( (int) $request['id'] );
		$connection = null === $source ? null : $this->services->connections()->find( $source->connectionId );
		if ( null === $source || null === $connection ) {
			return $this->error( 'swc_not_found', __( 'Source not found.', 'sw-cloudcalendar' ), 404 );
		}
		try {
			$month    = (string) ( $request['month'] ?? $this->services->clock()->now()->setTimezone( $source->timeZone() )->format( 'Y-m' ) );
			$start    = new \DateTimeImmutable( $month . '-01 00:00:00', $source->timeZone() );
			$page     = max( 1, (int) $request['page'] );
			$per_page = max( 1, min( 100, (int) $request['per_page'] ) );
			$range    = \SW\Calendar\Application\Render\QueryRange::fromLocal( $start, $start->modify( '+1 month' ) );
			if ( \SW\Calendar\Domain\Connection\Provider::Local === $connection->provider ) {
				$adapter = $this->services->local_sources()->get( (string) $connection->setting( 'adapter', '' ) );
				if ( null === $adapter || \SW\Calendar\Domain\Connection\ConnectionStatus::Connected !== $connection->status ) {
					return $this->error( 'swc_unavailable', __( 'Local source unavailable.', 'sw-cloudcalendar' ), 503 );
				}
				if ( ! $adapter->canReadCalendar( $source->externalId, get_current_user_id() ) ) {
					return $this->error( 'swc_forbidden', __( 'The request was not permitted.', 'sw-cloudcalendar' ), 403 );
				}
				$result = $adapter->query(
					new \SW\Calendar\Application\Local\LocalEventQuery(
						array( $source->externalId ),
						$range,
						limit: $per_page,
						offset: ( $page - 1 ) * $per_page,
					)
				);
				$events = array();
				foreach ( $result->items as $event ) {
					if ( $event->calendarId === $source->externalId && \SW\Calendar\Domain\Event\OccurrenceStatus::Cancelled !== $event->status ) {
						$events[] = $event->occurrence( $source->id );
					}
				}
				$total = $result->total;
			} else {
				$result = $this->services->occurrences()->find( array( $source->id ), $range, null, null, array(), false, $per_page, ( $page - 1 ) * $per_page, 0 );
				$events = $result['items'];
				$total  = $result['total'];
			}
			$items = array();
			foreach ( $events as $event ) {
				$items[] = array(
					'title'    => $event->title,
					'start'    => $event->startUtc->format( DATE_ATOM ),
					'end'      => $event->endUtc->format( DATE_ATOM ),
					'all_day'  => $event->allDay,
					'location' => $event->location,
				);
			}
			return $this->collection( $items, $total, $page, $per_page );
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
