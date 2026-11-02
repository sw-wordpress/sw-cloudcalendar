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
		preg_match_all( '/class="swc-events__item[^"]*"[^>]* data-swc-event="(\d+)"/', $html, $m );
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
	public function test_month_filtered_lists_and_cards_keep_all_pages_and_month_in_links(): void {
		$events = array();
		$start = new DateTimeImmutable( '2026-11-05T10:00:00Z' );
		for ( $i = 0; $i < 60; ++$i ) {
			$time = $start->modify( '+' . $i . ' minutes' );
			$events[] = new \SW\Calendar\Domain\Event\Occurrence(
				$this->source, 'monthly-' . $i, 'monthly-' . $i, null, null,
				'Monthly event ' . $i, '', '', '', '', $time, $time->modify( '+1 minute' ),
				'UTC', false, \SW\Calendar\Domain\Event\OccurrenceStatus::Confirmed
			);
		}
		foreach ( array( '2026-10-05', '2026-12-05' ) as $date ) {
			$time = new DateTimeImmutable( $date . 'T10:00:00Z' );
			$events[] = new \SW\Calendar\Domain\Event\Occurrence(
				$this->source, $date, $date, null, null,
				'Outside selected month', '', '', '', '', $time, $time->modify( '+1 hour' ),
				'UTC', false, \SW\Calendar\Domain\Event\OccurrenceStatus::Confirmed
			);
		}
		$this->services->occurrences()->replaceSnapshot( $this->source, $events, 2 );
		foreach ( array( 'list', 'cards' ) as $mode ) {
			$view = $this->view( array( $this->source ), $mode );
			$first = $this->services->renderer()->render( $view, array( 'month' => '2026-11' ), null );
			$this->assertSame( 60, $first->total );
			$this->assertCount( 50, $this->ids( $first->html ) );
			$this->assertStringContainsString( 'data-swc-month="2026-12"', $first->html );
			$this->assertStringContainsString( 'data-swc-page="2"', $first->html );
			$this->assertStringNotContainsString( 'data-swc-page="-1"', $first->html );
			$this->assertStringNotContainsString( 'Outside selected month', $first->html );

			$document = new DOMDocument();
			$document->loadHTML( $first->html, LIBXML_NOERROR | LIBXML_NOWARNING );
			$link = ( new DOMXPath( $document ) )->query( '//a[@data-swc-page="2"]' )->item( 0 );
			$this->assertInstanceOf( DOMElement::class, $link );
			parse_str( (string) wp_parse_url( $link->getAttribute( 'href' ), PHP_URL_QUERY ), $query );
			$this->assertSame( '2026-11', $query['swc_month'] );
			$this->assertSame( '2', $query['swc_page'] );
			$_GET = $query;
			try {
				$second = do_shortcode( '[sw_calendar id="' . $view . '"]' );
			} finally {
				$_GET = array();
			}
			$this->assertCount( 10, $this->ids( $second ) );
			$this->assertSame( array(), array_intersect( $this->ids( $first->html ), $this->ids( $second ) ) );
			$this->assertStringContainsString( 'data-swc-page="1"', $second );
			$this->assertStringNotContainsString( 'data-swc-page="3"', $second );
			$this->assertStringNotContainsString( 'Outside selected month', $second );
			$this->assertStringNotContainsString( 'swc-nav', $this->services->renderer()->render( $view, array( 'month' => '2026-11', 'navigation' => false ), null )->html );

			$page_data = $this->rest( 'GET', '/views/' . $view . '/events', array( 'month' => '2026-11', 'page' => 2, 'per_page' => 50 ) )->get_data();
			$this->assertCount( 10, $page_data['events'] );
			$this->assertSame( 60, $page_data['pagination']['total'] );
			$this->assertTrue( $page_data['pagination']['has_previous'] );
			$this->assertFalse( $page_data['pagination']['has_next'] );
		}
	}

	public function test_month_switch_clears_the_previous_page_and_month_pages_never_leave_the_range(): void {
		$urls = new \SW\Calendar\Infrastructure\WordPress\WpUrlGenerator();
		$urls->set_base_url( 'https://example.org/termine/?swc_page=2&swc_month=2026-11' );
		parse_str( (string) wp_parse_url( $urls->month( 12, '2026-12' ), PHP_URL_QUERY ), $query );
		$this->assertSame( '2026-12', $query['swc_month'] );
		$this->assertArrayNotHasKey( 'swc_page', $query );
		$view = $this->view( array( $this->source ) );
		$backwards = $this->services->renderer()->render( $view, array( 'month' => '2026-11', 'page' => -1 ), null );
		$first = $this->services->renderer()->render( $view, array( 'month' => '2026-11', 'page' => 1 ), null );
		$this->assertSame( $this->ids( $first->html ), $this->ids( $backwards->html ) );
		$this->assertStringNotContainsString( 'data-swc-page="-1"', $backwards->html );
	}

}
