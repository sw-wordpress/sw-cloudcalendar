<?php

use SW\Calendar\Application\Local\LocalEvent;
use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Infrastructure\WordPress\WpUrlGenerator;

final class CalendarControlsTest extends SWC_TestCase {
	private int $source_id;
	private int $view_id;

	public function set_up(): void {
		parent::set_up();
		$this->source_id = $this->source( $this->ics_connection() );
		$start = new DateTimeImmutable( '2026-11-03T10:00:00Z' );
		$events = array();
		foreach ( array(
			array( 'Chorprobe', 'Gemeindehaus', 'Musik' ),
			array( 'Workshop', 'Kirche', 'Chor und Musik' ),
			array( 'Meeting', 'Chorraum', 'Planung' ),
			array( 'Sport', 'Halle', 'Training' ),
		) as $i => $values ) {
			$at = $start->modify( '+' . $i . ' days' );
			$events[] = ( new LocalEvent( (string) $i, 'cal', $values[0], $at, $at->modify( '+1 hour' ), descriptionText: $values[2], location: $values[1] ) )->occurrence( $this->source_id );
		}
		$this->services->occurrences()->replaceSnapshot( $this->source_id, $events, 1 );
		$this->view_id = $this->services->views()->save( null, 'Controls', true, array(
			'source_ids' => array( $this->source_id ),
			'display_mode' => 'list',
			'allowed_modes' => array( 'list', 'month' ),
			'timezone' => 'Europe/Berlin',
			'default_filters' => array( 'mode_switcher' => true ),
		) );
	}

	public function test_optional_tabs_only_show_allowed_modes_and_use_resolved_active_mode(): void {
		$result = $this->services->renderer()->render( $this->view_id, array( 'month' => '2026-11', 'view' => 'cards' ), null );
		$this->assertStringContainsString( 'data-swc-switch="list"', $result->html );
		$this->assertLessThan( strpos( $result->html, 'data-swc-switch="month"' ), strpos( $result->html, 'data-swc-switch="list"' ) );
		$this->assertStringContainsString( 'data-swc-switch="month"', $result->html );
		$this->assertStringNotContainsString( 'data-swc-switch="cards"', $result->html );
		$this->assertSame( 1, substr_count( $result->html, 'aria-current="page"' ) );
		$month = $this->services->renderer()->render( $this->view_id, array( 'month' => '2026-11', 'view' => 'month' ), null )->html;
		$this->assertStringContainsString( 'class="swc-search"', $month );
		$this->assertStringContainsString( 'swc-month__events', $month );
		$this->assertStringContainsString( '--swc-event-text:#ffffff', $month );
		$this->assertStringContainsString( 'title="', $month );
		$hidden = $this->services->renderer()->render( $this->view_id, array( 'mode_switcher' => false, 'show_search' => false ), null )->html;
		$this->assertStringNotContainsString( 'swc-mode-tabs', $hidden );
		$this->assertStringNotContainsString( 'class="swc-search"', $hidden );
		$single = $this->view( array( $this->source_id ) );
		$this->assertStringNotContainsString( 'swc-mode-tabs', $this->services->renderer()->render( $single, array( 'mode_switcher' => true ), null )->html );
	}

	public function test_month_search_works_with_or_without_tabs(): void {
		foreach ( array( true, false ) as $tabs ) {
			$result = $this->services->renderer()->render( $this->view_id, array( 'view' => 'month', 'month' => '2026-11', 'search' => 'Chor', 'mode_switcher' => $tabs ), null );
			$this->assertSame( 3, $result->total );
			$this->assertStringContainsString( 'class="swc-search"', $result->html );
			$this->assertStringContainsString( 'placeholder="Search events"', $result->html );
			$this->assertStringContainsString( 'aria-description=', $result->html );
			$this->assertStringContainsString( 'swc-search__icon', $result->html );
			$this->assertStringNotContainsString( '>Sport<', $result->html );
			if ( ! $tabs ) {
				$this->assertStringNotContainsString( 'data-swc-switch', $result->html );
			}
		}
	}

	public function test_search_matches_title_description_and_location_across_pages_and_is_escaped(): void {
		$result = $this->services->renderer()->render( $this->view_id, array( 'month' => '2026-11', 'search' => 'Chor', 'limit' => 1 ), null );
		$this->assertSame( 3, $result->total );
		$this->assertStringContainsString( '3 events found', $result->html );
		$this->assertStringContainsString( 'name="swc_search" value="Chor"', $result->html );
		$this->assertStringContainsString( 'swc_search=Chor', $result->html );
		$this->assertStringContainsString( 'swc_mode=list', $result->html );
		$second = $this->services->renderer()->render( $this->view_id, array( 'month' => '2026-11', 'search' => 'Chor', 'limit' => 1, 'page' => 2 ), null );
		$this->assertStringContainsString( 'Workshop', $second->html );
		$none = $this->services->renderer()->render( $this->view_id, array( 'month' => '2026-11', 'search' => '"><script>x</script>' ), null );
		$this->assertSame( 0, $none->total );
		$this->assertStringNotContainsString( '<script>', $none->html );
		$this->assertStringContainsString( '&quot;&gt;&lt;script&gt;', $none->html );
	}

	public function test_empty_search_clears_a_default_search_and_filters_work_via_rest(): void {
		$view = $this->services->views()->save( null, 'Search default', true, array(
			'source_ids' => array( $this->source_id ), 'display_mode' => 'list',
			'default_filters' => array( 'search' => 'Nobody' ),
		) );
		$this->assertSame( 0, $this->services->renderer()->render( $view, array(), null )->total );
		$this->assertSame( 4, $this->services->renderer()->render( $view, array( 'search' => '' ), null )->total );
		$response = $this->rest( 'GET', '/views/' . $this->view_id . '/render', array( 'month' => '2026-11', 'search' => 'Chor', 'show_search' => 'false', 'mode_switcher' => 'false' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( 'swc-search', $response->get_data()['html'] );
		$this->assertStringNotContainsString( 'swc-mode-tabs', $response->get_data()['html'] );
	}

	public function test_no_javascript_controls_are_targeted_and_preserve_the_embedding_page(): void {
		$this->services->urls()->set_base_url( 'https://example.org/?page_id=42&swc_search=old&swc_mode=month' );
		$url = $this->services->urls()->calendar( $this->view_id, array( 'view' => 'list', 'search' => 'Chor & Musik', 'page' => 1 ) );
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( '42', $query['page_id'] );
		$this->assertSame( 'Chor & Musik', $query['swc_search'] );
		$this->assertSame( 'list', $query['swc_mode'] );
		$_GET = array( WpUrlGenerator::VIEW_PARAM => $this->view_id, WpUrlGenerator::MODE_PARAM => 'month', WpUrlGenerator::SEARCH_PARAM => 'Chor', WpUrlGenerator::MONTH_PARAM => '2026-11' );
		$html = do_shortcode( '[sw_calendar id="' . $this->view_id . '"]' );
		$this->assertStringContainsString( 'swc-mode-month', $html );
		$this->assertStringNotContainsString( 'Sport', $html );
		$other = $this->view( array( $this->source_id ) );
		$this->assertStringContainsString( 'swc-mode-list', do_shortcode( '[sw_calendar id="' . $other . '"]' ) );
		$_GET = array();
		$html = $this->services->renderer()->render( $this->view_id, array(), null )->html;
		$this->assertStringContainsString( 'name="page_id" value="42"', $html );
	}

	/** @dataProvider mode_switch_round_trips */
	public function test_mode_switch_round_trip_restores_the_list_events( bool $ajax, string $date_filter, string $first_day ): void {
		$start = new DateTimeImmutable( '2026-10-22T10:00:00Z' );
		$events = array();
		for ( $i = 0; $i < 16; ++$i ) {
			$at = $start->modify( '+' . $i . ' days' );
			$events[] = ( new LocalEvent( (string) $i, 'cal', 'Choir ' . $at->format( 'm-d' ), $at, $at->modify( '+1 hour' ) ) )->occurrence( $this->source_id );
		}
		$events[] = ( new LocalEvent( 'other', 'cal', 'Sports meeting', $start, $start->modify( '+1 hour' ) ) )->occurrence( $this->source_id );
		$this->services->occurrences()->replaceSnapshot( $this->source_id, $events, 2 );

		$shortcode = '[sw_calendar id="' . $this->view_id . '" limit="10"' . $date_filter . ']';
		$_GET = array( 'swc_view' => $this->view_id, 'swc_search' => 'Choir' );
		try {
			$initial = do_shortcode( $shortcode );
			$month = $this->switch_mode( $initial, 'month', $ajax, $shortcode );
			$list = $this->switch_mode( $month, 'list', $ajax, $shortcode );
		} finally {
			$_GET = array();
		}

		$this->assertStringContainsString( 'swc-mode-month', $month );
		$this->assertStringContainsString( 'swc-mode-list', $list );
		$this->assertStringContainsString( 'name="swc_search" value="Choir"', $list );
		$this->assertStringNotContainsString( 'Sports meeting', $month );
		preg_match_all( '/Choir \d{2}-\d{2}/', $initial, $initial_titles );
		preg_match_all( '/Choir \d{2}-\d{2}/', $list, $list_titles );
		$this->assertCount( 10, $initial_titles[0] );
		$this->assertSame( 'Choir ' . $first_day, $initial_titles[0][0] );
		$this->assertSame( $initial_titles[0], $list_titles[0] );
	}

	public static function mode_switch_round_trips(): array {
		return array(
			'AJAX upcoming' => array( true, '', '10-25' ),
			'plain links upcoming' => array( false, '', '10-25' ),
			'AJAX explicit month' => array( true, ' month="2026-10"', '10-22' ),
			'plain links explicit month' => array( false, ' month="2026-10"', '10-22' ),
			'AJAX explicit date range' => array( true, ' start="2026-10-23" end="2026-11-03"', '10-23' ),
			'plain links explicit date range' => array( false, ' start="2026-10-23" end="2026-11-03"', '10-23' ),
		);
	}

	private function switch_mode( string $html, string $mode, bool $ajax, string $shortcode ): string {
		$document = new DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new DOMXPath( $document );
		$link = $xpath->query( '//a[@data-swc-switch="' . $mode . '"]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $link );
		if ( $ajax ) {
			$calendar = $xpath->query( '//*[@data-swc-state]' )->item( 0 );
			$this->assertInstanceOf( DOMElement::class, $calendar );
			$state = json_decode( $calendar->getAttribute( 'data-swc-state' ), true, 512, JSON_THROW_ON_ERROR );
			$params = array_replace( $state, array( 'view' => $link->getAttribute( 'data-swc-switch' ), 'page' => 1 ) );
			$response = $this->rest( 'GET', '/views/' . $this->view_id . '/render', $params );
			$this->assertSame( 200, $response->get_status() );
			return $response->get_data()['html'];
		}

		parse_str( (string) wp_parse_url( $link->getAttribute( 'href' ), PHP_URL_QUERY ), $_GET );
		return do_shortcode( $shortcode );
	}

	public function test_cached_controls_keep_the_correct_embedding_page(): void {
		$urls = $this->services->urls();
		$renderer = $this->services->renderer();
		$urls->set_base_url( 'https://example.org/?page_id=42' );
		$renderer->render( $this->view_id, array( 'month' => '2026-11' ), null );
		$this->assertTrue( $renderer->render( $this->view_id, array( 'month' => '2026-11' ), null )->cacheHit );
		$urls->set_base_url( 'https://example.org/?page_id=43' );
		$result = $renderer->render( $this->view_id, array( 'month' => '2026-11' ), null );
		$this->assertFalse( $result->cacheHit );
		$this->assertStringContainsString( 'name="page_id" value="43"', $result->html );
		$this->assertStringNotContainsString( 'name="page_id" value="42"', $result->html );
	}

	public function test_search_and_mode_switching_never_extend_source_permissions(): void {
		$private = $this->source( $this->ics_connection(), Visibility::Roles, array( 'editor' ) );
		$view = $this->services->views()->save( null, 'Private', true, array(
			'source_ids' => array( $private ), 'display_mode' => 'list', 'allowed_modes' => array( 'month' ),
			'default_filters' => array( 'mode_switcher' => true ),
		) );
		$result = $this->services->renderer()->render( $view, array( 'view' => 'month', 'search' => 'Chor', 'source' => (string) $this->source_id ), null );
		$this->assertSame( 0, $result->total );
		$this->assertStringNotContainsString( 'Chorprobe', $result->html );
	}
}
