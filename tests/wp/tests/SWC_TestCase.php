<?php
/**
 * Base class: fresh service container with fake HTTP and a frozen clock, helpers to create data.
 */

use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Presentation\Admin\SourceManager;
use SW\Calendar\Presentation\Admin\ViewManager;
use SW\Calendar\Services;
use SW\Calendar\Support\FrozenClock;
use SW\Calendar\Tests\Support\FakeHttpClient;

abstract class SWC_TestCase extends WP_UnitTestCase {

	protected FakeHttpClient $http;
	protected FrozenClock $clock;
	protected Services $services;

	public function set_up(): void {
		parent::set_up();
		Services::reset();
		// Controllers are bound to the container at rest_api_init: rebuild the server for the fresh container.
		global $wp_rest_server;
		$wp_rest_server = null;
		$this->services = Services::instance();
		$this->http     = new FakeHttpClient();
		$this->clock    = new FrozenClock( '2026-10-24T12:00:00Z' );
		$this->services->set( 'http', $this->http );
		$this->services->set( 'clock', $this->clock );
		update_option( 'timezone_string', 'Europe/Berlin' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		Services::reset();
		parent::tear_down();
	}

	protected function admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_role( 'administrator' )->add_cap( 'manage_sw_calendar' );
		// Role caps are cached on the user object.
		wp_set_current_user( $id );
		wp_get_current_user()->get_role_caps();
		return $id;
	}

	protected function ics_connection(): int {
		return $this->services->connections()->create( Provider::Ics, 'Feed', AuthMode::None, ConnectionStatus::Connected, array( 'url' => 'https://calendar.example.org/feed.ics' ) );
	}

	/**
	 * @param list<string>          $roles
	 * @param array<string, mixed>  $settings
	 */
	protected function source( int $connection_id, Visibility $visibility = Visibility::Public, array $roles = array(), array $settings = array() ): int {
		$id = $this->services->sources()->create( $connection_id, 'feed-' . wp_rand(), 'Gemeinde', '#336699', 'Europe/Berlin', $visibility, $roles, SourceSettings::fromArray( $settings ) );
		$this->services->sources()->update( $id, 'Gemeinde', '#336699', 'Europe/Berlin', $visibility, $roles, SourceSettings::fromArray( $settings ), \SW\Calendar\Domain\Source\SourceStatus::Active );
		return $id;
	}

	/**
	 * @param list<int> $source_ids
	 */
	protected function view( array $source_ids, string $mode = 'list' ): int {
		return $this->services->views()->save(
			null,
			'Termine',
			true,
			array(
				'source_ids'   => $source_ids,
				'display_mode' => $mode,
				'html_cache'   => true,
			)
		);
	}

	protected function feed( string $file = 'parish.ics' ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/ics/' . $file );
	}

	protected function sync( int $source_id ): \SW\Calendar\Application\Sync\SyncReport {
		$this->http->push( 200, $this->feed(), array( 'ETag' => '"v1"' ) );
		$report = $this->services->sync()->sync( $source_id );
		$this->assertSame( 'success', $report->status, (string) $report->message );
		return $report;
	}

	/**
	 * @param array<string, mixed> $params
	 * @param array<string, mixed>|null $body
	 */
	protected function rest( string $method, string $path, array $params = array(), ?array $body = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/sw-cloudcalendar/v1' . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}
}
