<?php

/**
 * Previous/next navigation of lists, cards and the month grid; sync interval in hours.
 */
final class NavigationTest extends SWC_TestCase {

	private int $source;

	public function set_up(): void {
		parent::set_up();
		$this->source = $this->source( $this->ics_connection() );
		$this->sync( $this->source );
	}

	/**
	 * @return list<int>
	 */
	private function ids( string $html ): array {
		preg_match_all( '/class="swc-events__item[^"]*" data-swc-event="(\d+)"/', $html, $m );
		return array_map( 'intval', $m[1] );
	}

	public function test_list_pages_forward_and_backward_without_overlap(): void {
		$view     = $this->view( array( $this->source ) );
		$renderer = $this->services->renderer();

		$first = $renderer->render( $view, array( 'limit' => '3' ), null )->html;
		$this->assertStringContainsString( 'data-swc-page="2"', $first, 'More events link' );
		$this->assertStringContainsString( 'data-swc-page="-1"', $first, 'Earlier events link (past events exist)' );
		$this->assertStringContainsString( 'swc-nav--list', $first, 'Appended although the preset container has no navigation placeholder' );

		$second = $renderer->render( $view, array( 'limit' => '3', 'page' => '2' ), null )->html;
		$this->assertCount( 3, $this->ids( $second ) );
		$this->assertSame( array(), array_intersect( $this->ids( $first ), $this->ids( $second ) ) );
		$this->assertStringContainsString( 'data-swc-page="1"', $second, 'Back from page 2 to page 1' );

		$earlier = $renderer->render( $view, array( 'limit' => '3', 'page' => '-1' ), null )->html;
		$earlier_ids = $this->ids( $earlier );
		$this->assertCount( 3, $earlier_ids );
		$this->assertSame( array(), array_intersect( $this->ids( $first ), $earlier_ids ), 'Ongoing events never appear on both sides' );
		$now = $this->clock->now();
		$ends = array();
		foreach ( $earlier_ids as $id ) {
			$occurrence = $this->services->occurrences()->findById( $id );
			$this->assertLessThanOrEqual( $now, $occurrence->endUtc, 'Backward pages only contain events that already ended' );
			$ends[] = $occurrence->startUtc->getTimestamp();
		}
		$sorted = $ends;
		sort( $sorted );
		$this->assertSame( $sorted, $ends, 'Earlier events are shown in chronological order' );
		$this->assertStringContainsString( 'data-swc-page="1"', $earlier, 'Forward from page -1 leads to page 1' );
		$this->assertStringContainsString( 'data-swc-page="-2"', $earlier );
	}

	public function test_navigation_can_be_disabled_per_view_and_per_shortcode(): void {
		$view = $this->view( array( $this->source ) );
		$this->assertStringNotContainsString( 'swc-nav', $this->services->renderer()->render( $view, array( 'limit' => '3', 'navigation' => 'false' ), null )->html );

		$this->admin();
		$updated = $this->rest( 'PUT', '/admin/views/' . $view, array(), array( 'show_navigation' => false ) );
		$this->assertFalse( $updated->get_data()['show_navigation'] );
		$this->assertStringNotContainsString( 'swc-nav', do_shortcode( '[sw_calendar id="' . $view . '" limit="3"]' ) );
		$this->assertStringContainsString( 'swc-nav--list', do_shortcode( '[sw_calendar id="' . $view . '" limit="3" navigation="true"]' ) );
	}

	public function test_month_navigation_and_switch(): void {
		$month = $this->view( array( $this->source ), 'month' );
		$html  = $this->services->renderer()->render( $month, array( 'month' => '2026-11' ), null )->html;
		$this->assertStringContainsString( 'data-swc-month="2026-10"', $html );
		$this->assertStringContainsString( 'data-swc-month="2026-12"', $html );
		$this->assertStringNotContainsString( 'data-swc-month', $this->services->renderer()->render( $month, array( 'month' => '2026-11', 'navigation' => 'false' ), null )->html );
	}

	public function test_page_parameter_without_javascript(): void {
		$view = $this->view( array( $this->source ) );
		$_GET = array(
			'swc_view' => (string) $view,
			'swc_page' => '2',
		);
		$html = do_shortcode( '[sw_calendar id="' . $view . '" limit="3"]' );
		$_GET = array();
		$this->assertSame( $this->ids( $this->services->renderer()->render( $view, array( 'limit' => '3', 'page' => '2' ), null )->html ), $this->ids( $html ) );
		$this->assertStringContainsString( 'swc_page=3', $html, 'Links keep working without JavaScript' );
	}

	public function test_rest_pagination_flags(): void {
		$view = $this->view( array( $this->source ) );
		$data = $this->rest( 'GET', '/views/' . $view . '/events', array( 'per_page' => 5 ) )->get_data()['pagination'];
		$this->assertTrue( $data['has_next'] );
		$this->assertTrue( $data['has_previous'] );
		$this->assertSame( 400, $this->rest( 'GET', '/views/' . $view . '/events', array( 'page' => '0' ) )->get_status() );
	}

	public function test_default_sync_interval_is_six_hours(): void {
		$state = $this->services->sync_state()->load( $this->source );
		$this->assertSame( 6 * 3600, $state->nextAttemptAt->getTimestamp() - $this->clock->now()->getTimestamp() );
		$this->admin();
		$invalid = $this->rest( 'PUT', '/admin/sources/' . $this->source, array(), array( 'settings' => array( 'sync_interval_hours' => 49 ) ) );
		$this->assertSame( 'invalid', $invalid->get_data()['data']['params']['settings.sync_interval_hours'] );
		$ok = $this->rest( 'PUT', '/admin/sources/' . $this->source, array(), array( 'settings' => array( 'sync_interval_hours' => 24 ) ) );
		$this->assertSame( 24, $ok->get_data()['settings']['sync_interval_hours'] );
	}
}
