<?php
/**
 * Templates per display mode: one view can design single displays differently while the others keep their standard.
 */

use SW\Calendar\Application\Local\LocalEvent;
use SW\Calendar\Domain\Template\Template;
use SW\Calendar\Domain\View\DisplayMode;
use SW\Calendar\Presentation\Admin\TemplateManager;

final class ModeTemplatesTest extends SWC_TestCase {

	/**
	 * Two November events plus the installed copies of a preset.
	 *
	 * @return array{0: int, 1: list<int>} source id and template ids (container, event, detail)
	 */
	private function seeded( string $preset ): array {
		$source = $this->source( $this->ics_connection() );
		$events = array();
		foreach ( array( '2026-11-03T10:00:00Z', '2026-11-05T18:00:00Z' ) as $i => $time ) {
			$start    = new DateTimeImmutable( $time );
			$events[] = ( new LocalEvent( (string) $i, 'cal', 'Event ' . $i, $start, $start->modify( '+1 hour' ) ) )->occurrence( $source );
		}
		$this->services->occurrences()->replaceSnapshot( $source, $events, 1 );
		$templates = array_map(
			static fn ( Template $template ): int => $template->id,
			( new TemplateManager( $this->services ) )->install_preset( $preset )
		);
		return array( $source, $templates );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function configured_view( int $source, array $config ): int {
		return $this->services->views()->save(
			null,
			'Termine',
			true,
			array_merge(
				array(
					'source_ids'    => array( $source ),
					'display_mode'  => 'list',
					'allowed_modes' => array( 'list', 'cards', 'month' ),
					'timezone'      => 'Europe/Berlin',
					'html_cache'    => false,
				),
				$config
			)
		);
	}

	private function html( int $view, string $mode ): string {
		$result = $this->services->renderer()->render(
			$view,
			array( 'view' => $mode, 'start' => '2026-11-01', 'end' => '2026-11-30', 'navigation' => 'false' ),
			null
		);
		$this->assertSame( 2, $result->total, 'Fixture renders in every display' );
		return $result->html;
	}

	public function test_event_template_choice_changes_only_that_display(): void {
		list( $source, $templates ) = $this->seeded( 'image-right-list' );
		$view                       = $this->configured_view( $source, array( 'event_template_ids_by_mode' => array( 'cards' => $templates[1] ) ) );

		$cards = $this->html( $view, 'cards' );
		$this->assertStringContainsString( 'swc-right-row__title', $cards, 'Cards use the chosen event template' );
		$this->assertStringNotContainsString( 'swc-card__title', $cards, 'The card preset is replaced' );
		$this->assertStringContainsString( 'swc-events--cards', $cards, 'Cards keep the card structure' );

		$list = $this->html( $view, 'list' );
		$this->assertStringContainsString( 'swc-row__title', $list, 'List keeps its preset' );
		$this->assertStringNotContainsString( 'swc-right-row', $list );

		$month = $this->html( $view, 'month' );
		$this->assertStringContainsString( 'swc-chip__title', $month, 'The month grid keeps its chip preset' );
		$this->assertStringNotContainsString( 'swc-right-row', $month, 'A card design never lands in the month grid' );
	}

	public function test_container_template_choice_changes_only_that_display(): void {
		list( $source, $templates ) = $this->seeded( 'image-right-list' );
		list( $container, $event )  = $templates;
		$view                       = $this->configured_view(
			$source,
			array(
				'display_mode'                   => 'cards',
				'container_template_ids_by_mode' => array( 'cards' => $container ),
				'event_template_ids_by_mode'     => array( 'cards' => $event ),
			)
		);

		$cards = $this->html( $view, 'cards' );
		$this->assertStringContainsString( 'swc-month-group', $cards, 'Cards use the month grouped container' );
		$this->assertStringContainsString( 'swc-right-row__title', $cards );
		$this->assertStringNotContainsString( 'swc-container__title', $cards, 'The container preset of the mode is replaced' );

		$list = $this->html( $view, 'list' );
		$this->assertStringContainsString( 'swc-container__title', $list, 'List keeps its own container preset' );
		$this->assertStringContainsString( 'swc-row__title', $list );
		$this->assertStringNotContainsString( 'swc-month-group', $list, 'The card container does not group the list' );
	}

	public function test_released_template_of_an_embed_still_wins(): void {
		list( $source, $templates ) = $this->seeded( 'image-right-list' );
		$classic                    = array_map(
			static fn ( Template $template ): int => $template->id,
			( new TemplateManager( $this->services ) )->install_preset( 'classic-list' )
		);
		$view                       = $this->configured_view(
			$source,
			array(
				'event_template_ids_by_mode' => array( 'cards' => $templates[1] ),
				'allowed_template_ids'       => array( $classic[1] ),
			)
		);

		$result = $this->services->renderer()->render(
			$view,
			array( 'view' => 'cards', 'template' => $classic[1], 'start' => '2026-11-01', 'end' => '2026-11-30', 'navigation' => 'false' ),
			null
		);
		$this->assertStringContainsString( 'swc-row__title', $result->html, 'The released template of the embed wins over the mode choice' );
		$this->assertStringNotContainsString( 'swc-right-row', $result->html );
		$this->assertStringContainsString( 'swc-events--cards', $result->html, 'The display mode itself stays cards' );

		$untouched = $this->html( $view, 'cards' );
		$this->assertStringContainsString( 'swc-right-row__title', $untouched, 'Without the parameter the mode choice applies' );
	}

	public function test_each_display_gets_its_own_cached_html(): void {
		list( $source, $templates ) = $this->seeded( 'image-right-list' );
		$view                       = $this->configured_view(
			$source,
			array(
				'event_template_ids_by_mode' => array( 'cards' => $templates[1] ),
				'html_cache'                 => true,
			)
		);
		$renderer                   = $this->services->renderer();
		$filter                     = array( 'start' => '2026-11-01', 'end' => '2026-11-30', 'navigation' => 'false' );

		$cards = $renderer->render( $view, $filter + array( 'view' => 'cards' ), null );
		$this->assertFalse( $cards->cacheHit );
		$again = $renderer->render( $view, $filter + array( 'view' => 'cards' ), null );
		$this->assertTrue( $again->cacheHit, 'Same display, same templates: cache hit' );
		$this->assertSame( $cards->html, $again->html );

		$list = $renderer->render( $view, $filter + array( 'view' => 'list' ), null );
		$this->assertFalse( $list->cacheHit, 'The cached card markup is never reused for the list' );
		$this->assertStringContainsString( 'swc-row__title', $list->html );
		$this->assertStringNotContainsString( 'swc-right-row', $list->html );
	}

	public function test_admin_api_validates_stores_and_protects_mode_templates(): void {
		$this->admin();
		list( $source, $templates ) = $this->seeded( 'image-right-list' );
		list( $container, $event )  = $templates;

		$wrong  = $this->rest(
			'POST',
			'/admin/views',
			array(),
			array(
				'name'                           => 'V',
				'source_ids'                     => array( $source ),
				'display_mode'                   => 'list',
				// Container template in the event map, and an unknown display mode.
				'event_template_ids_by_mode'     => array( 'cards' => $container ),
				'container_template_ids_by_mode' => array( 'week' => $container ),
			)
		);
		$this->assertSame( 400, $wrong->get_status() );
		$params = $wrong->get_data()['data']['params'];
		$this->assertSame( 'invalid_template', $params['event_template_ids_by_mode.cards'] );
		$this->assertSame( 'invalid_mode', $params['container_template_ids_by_mode'] );

		$created = $this->rest(
			'POST',
			'/admin/views',
			array(),
			array(
				'name'                           => 'V',
				'source_ids'                     => array( $source ),
				'display_mode'                   => 'list',
				'allowed_modes'                  => array( 'list', 'cards' ),
				'container_template_ids_by_mode' => array( 'cards' => $container ),
				'event_template_ids_by_mode'     => array( 'cards' => $event, 'list' => null ),
				'html_cache'                     => false,
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$stored  = $created->get_data();
		$this->assertSame( array( 'cards' => $event ), (array) $stored['event_template_ids_by_mode'], 'An empty choice inherits again' );
		$this->assertSame( array( 'cards' => $container ), (array) $stored['container_template_ids_by_mode'] );
		$this->assertSame( 409, $this->rest( 'DELETE', '/admin/templates/' . $event )->get_status(), 'Used through the mode map only' );

		$cards  = do_shortcode( '[sw_calendar id="' . $stored['id'] . '" view="cards" start="2026-11-01" end="2026-11-30"]' );
		$this->assertStringContainsString( 'swc-right-row__title', $cards );
		$this->assertStringContainsString( 'swc-month-group', $cards, 'The container override reaches the frontend' );

		$cleared = $this->rest( 'PUT', '/admin/views/' . $stored['id'], array(), array( 'event_template_ids_by_mode' => array( 'cards' => null ) ) );
		$this->assertSame( 200, $cleared->get_status() );
		$this->assertSame( array(), (array) $cleared->get_data()['event_template_ids_by_mode'] );
		$again   = do_shortcode( '[sw_calendar id="' . $stored['id'] . '" view="cards" start="2026-11-01" end="2026-11-30"]' );
		$this->assertStringNotContainsString( 'swc-right-row', $again, 'Without the choice the card preset applies' );
		$this->assertStringContainsString( 'swc-card__title', $again );
		$this->assertSame( 409, $this->rest( 'DELETE', '/admin/templates/' . $container )->get_status(), 'The container override is still in use' );
	}

	public function test_unusable_entries_of_a_stored_map_are_ignored(): void {
		list( $source ) = $this->seeded( 'classic-list' );
		$id             = $this->configured_view(
			$source,
			array(
				'event_template_ids_by_mode' => array(
					'cards' => 0,
					'week'  => 5,
					'month' => '12',
					'list'  => null,
				),
			)
		);
		$view           = $this->services->views()->find( $id );
		$this->assertSame( array( 'month' => 12 ), $view->eventTemplateIdsByMode, 'Unknown modes and empty ids are dropped' );
		$this->assertSame( 12, $view->eventTemplateFor( DisplayMode::Month ) );
		$this->assertNull( $view->eventTemplateFor( DisplayMode::Cards ) );
		$this->assertSame( array(), $view->containerTemplateIdsByMode );
	}

	public function test_views_without_mode_maps_keep_one_template_for_every_display(): void {
		list( $source, $templates ) = $this->seeded( 'image-right-list' );
		$view                       = $this->configured_view(
			$source,
			array(
				'container_template_id' => $templates[0],
				'event_template_id'     => $templates[1],
			)
		);
		$loaded                     = $this->services->views()->find( $view );
		$this->assertSame( array(), $loaded->containerTemplateIdsByMode );
		$this->assertSame( array(), $loaded->eventTemplateIdsByMode );
		foreach ( array( 'list', 'cards', 'month' ) as $mode ) {
			$this->assertStringContainsString(
				'swc-right-row__title',
				$this->html( $view, $mode ),
				'A view wide template still applies to every display (' . $mode . ')'
			);
		}
	}

	public function test_primary_color_is_stored_validated_and_rendered_for_tabs_and_search(): void {
		$source = $this->source( $this->ics_connection() );
		$body   = array(
			'name'          => 'Farbe',
			'source_ids'    => array( $source ),
			'display_mode'  => 'list',
			'allowed_modes' => array( 'list', 'cards' ),
			'timezone'      => 'Europe/Berlin',
			'html_cache'    => false,
		);

		$invalid = $this->rest( 'POST', '/admin/views', array(), $body + array( 'primary_color' => 'red' ) );
		$this->assertSame( 400, $invalid->get_status(), 'Only #rrggbb is accepted' );

		$created = $this->rest( 'POST', '/admin/views', array(), $body + array( 'primary_color' => '#AA3300' ) );
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( '#aa3300', $created->get_data()['primary_color'] );
		$id = (int) $created->get_data()['id'];

		$html = $this->services->renderer()->render( $id, array( 'mode_switcher' => 'true' ), null )->html;
		$this->assertStringContainsString( '--swc-primary:#aa3300;--swc-primary-text:#ffffff', $html );
		$this->assertStringContainsString( 'swc-has-primary', $html );

		$reset = $this->rest( 'PUT', '/admin/views/' . $id, array(), $body + array( 'primary_color' => '' ) );
		$this->assertSame( '', $reset->get_data()['primary_color'] );
		$plain = $this->services->renderer()->render( $id, array(), null )->html;
		$this->assertStringNotContainsString( '--swc-primary', $plain, 'Without a colour the stylesheet default stays' );
	}
}
