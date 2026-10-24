<?php

use SW\Calendar\Domain\Source\SourceStatus;
use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Presentation\Admin\SourceManager;

/**
 * ACL matrix over REST (SPECIFICATIONS.md §9, E2E-09/E2E-10).
 */
final class AccessTest extends SWC_TestCase {

	private int $public_source;
	private int $roles_source;
	private int $view;

	public function set_up(): void {
		parent::set_up();
		$connection          = $this->ics_connection();
		$this->public_source = $this->source( $connection );
		$this->roles_source  = $this->source( $connection, Visibility::Roles, array( 'editor' ) );
		$this->sync( $this->public_source );
		$this->sync( $this->roles_source );
		$this->view = $this->view( array( $this->public_source, $this->roles_source ) );
	}

	/**
	 * @return list<int>
	 */
	private function event_sources( array $params = array() ): array {
		$response = $this->rest( 'GET', '/views/' . $this->view . '/events', $params + array( 'start' => '2026-10-01', 'end' => '2026-12-31', 'per_page' => 100 ) );
		$this->assertSame( 200, $response->get_status() );
		return array_values( array_unique( array_map( static fn ( array $e ): int => $e['source_id'], $response->get_data()['events'] ) ) );
	}

	public function test_guest_sees_only_public_source(): void {
		$this->assertSame( array( $this->public_source ), $this->event_sources() );
		$response = $this->rest( 'GET', '/views/' . $this->view . '/events', array( 'start' => '2026-10-01', 'end' => '2026-12-31' ) );
		$this->assertSame( 'private, no-store, max-age=0', $response->get_headers()['Cache-Control'], 'Mixed view responses are never publicly cacheable' );
	}

	public function test_user_without_role_and_with_role(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array( $this->public_source ), $this->event_sources() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$sources = $this->event_sources();
		sort( $sources );
		$this->assertSame( array( $this->public_source, $this->roles_source ), $sources );
	}

	public function test_source_parameter_cannot_extend_view(): void {
		$other_view = $this->view( array( $this->public_source ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = $this->rest( 'GET', '/views/' . $other_view . '/events', array( 'source' => (string) $this->roles_source, 'start' => '2026-10-01', 'end' => '2026-12-31' ) );
		$this->assertSame( array(), $response->get_data()['events'] );
		$this->assertSame( 0, $response->get_data()['pagination']['total'], 'No leak through counts' );
	}

	public function test_event_detail_of_restricted_source_is_404_for_guests(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$events = $this->rest( 'GET', '/views/' . $this->view . '/events', array( 'source' => (string) $this->roles_source, 'start' => '2026-10-01', 'end' => '2026-12-31' ) )->get_data()['events'];
		$this->assertNotEmpty( $events );
		$id = $events[0]['id'];
		$this->assertSame( 200, $this->rest( 'GET', '/events/' . $id, array( 'view' => $this->view ) )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 404, $this->rest( 'GET', '/events/' . $id, array( 'view' => $this->view ) )->get_status() );
		// An unrelated view cannot be used to read it either.
		$other = $this->view( array( $this->public_source ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 404, $this->rest( 'GET', '/events/' . $id, array( 'view' => $other ) )->get_status() );
	}

	public function test_visibility_change_is_effective_before_cached_html(): void {
		$public_view = $this->view( array( $this->public_source ) );
		$params      = array( 'start' => '2026-10-01', 'end' => '2026-11-30' );
		$first       = $this->services->renderer()->render( $public_view, $params, null );
		$this->assertStringContainsString( 'Gottesdienst', $first->html );
		$this->assertTrue( $this->services->renderer()->render( $public_view, $params, null )->cacheHit );

		$this->admin();
		( new SourceManager( $this->services ) )->save( $this->public_source, array( 'visibility' => 'authenticated' ) );
		wp_set_current_user( 0 );
		$after = $this->services->renderer()->render( $public_view, $params, null );
		$this->assertFalse( $after->cacheHit );
		$this->assertStringNotContainsString( 'Gottesdienst', $after->html );
	}

	public function test_disabled_source_is_blocked_immediately(): void {
		$this->services->sources()->setStatus( $this->public_source, SourceStatus::Disabled );
		$this->assertSame( array(), $this->event_sources() );
	}

	public function test_invalid_filters_are_rejected_by_rest(): void {
		$response = $this->rest( 'GET', '/views/' . $this->view . '/render', array( 'limit' => '1000', 'category' => 'x' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'limit', $response->get_data()['data']['params'] );
		$this->assertSame( 'not_supported', $response->get_data()['data']['params']['category'] );
	}

	public function test_admin_routes_require_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', '/admin/sources' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/admin/connections' )->get_status() );
		$this->admin();
		$this->assertSame( 200, $this->rest( 'GET', '/admin/sources' )->get_status() );
		$connections = $this->rest( 'GET', '/admin/connections' )->get_data()['items'];
		$this->assertArrayNotHasKey( 'secrets', $connections[0] );
	}

	public function test_unknown_view_is_404(): void {
		$this->assertSame( 404, $this->rest( 'GET', '/views/999999/events' )->get_status() );
	}
}
