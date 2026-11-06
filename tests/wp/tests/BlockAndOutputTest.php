<?php
/**
 * Block registration/rendering and the shared shortcode/block output path (deep links, attributes, assets, nonce).
 */

use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Presentation\Block\CalendarBlock;
use SW\Calendar\Presentation\Frontend\CalendarOutput;

final class BlockAndOutputTest extends SWC_TestCase {

	private int $source;

	public function set_up(): void {
		parent::set_up();
		$this->source = $this->source( $this->ics_connection() );
		$this->sync( $this->source );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function first_event( string $html ): int {
		$this->assertSame( 1, preg_match( '/data-swc-event="(\d+)"/', $html, $m ) );
		return (int) $m[1];
	}

	public function test_block_registers_from_metadata_with_editor_asset_file(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( 'sw-cloudcalendar/calendar' ) ) {
			$registry->unregister( 'sw-cloudcalendar/calendar' );
		}
		wp_deregister_script( 'sw-cloudcalendar-calendar-editor-script' );

		( new CalendarBlock() )->register();

		$type = $registry->get_registered( 'sw-cloudcalendar/calendar' );
		$this->assertNotNull( $type );
		$this->assertIsCallable( $type->render_callback );
		$this->assertArrayHasKey( 'viewId', $type->attributes );
		$script = wp_scripts()->registered['sw-cloudcalendar-calendar-editor-script'] ?? null;
		$this->assertNotNull( $script, 'Editor script registered from block.json' );
		$this->assertContains( 'wp-server-side-render', $script->deps, 'Dependencies come from index.asset.php' );
	}

	public function test_block_renders_wrapped_calendar_and_nothing_without_view(): void {
		$view = $this->view( array( $this->source ) );
		$html = render_block(
			array(
				'blockName'    => 'sw-cloudcalendar/calendar',
				'attrs'        => array(
					'viewId'     => $view,
					'limit'      => 2,
					'showImages' => false,
					'className'  => 'extra',
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringStartsWith( '<div ', $html );
		$this->assertStringContainsString( 'swc-block', $html );
		$this->assertCount( 2, array_unique( preg_match_all( '/data-swc-event="(\d+)"/', $html, $m ) ? $m[1] : array() ) );

		$this->assertSame( '', ( new CalendarBlock() )->render( array( 'viewId' => 0 ) ) );
	}

	public function test_output_ignores_missing_ids_and_unknown_views(): void {
		$output = new CalendarOutput( $this->services );
		$this->assertSame( '', $output->render( array() ) );
		$this->assertSame( '', $output->render( array( 'id' => 'abc' ) ) );
		$this->assertSame( '', $output->render( array( 'id' => 999999 ) ), 'Unknown view renders nothing' );
	}

	public function test_deep_link_shows_event_detail_with_back_link(): void {
		$view   = $this->view( array( $this->source ) );
		$output = new CalendarOutput( $this->services );
		$event  = $this->first_event( $output->render( array( 'id' => $view ) ) );

		$_GET = array(
			'swc_view'  => (string) $view,
			'swc_event' => (string) $event,
		);
		$html = $output->render( array( 'id' => $view ) );
		$this->assertStringContainsString( 'class="swc-back"', $html );
		$this->assertStringContainsString( 'data-swc-back="' . $view . '"', $html );
		$this->assertStringNotContainsString( 'swc_event=', $html, 'Back link drops the event parameter' );

		// An unknown event falls back to the calendar itself.
		$_GET['swc_event'] = '999999';
		$fallback          = $output->render( array( 'id' => $view ) );
		$this->assertStringNotContainsString( 'swc-back', $fallback );
		$this->assertStringContainsString( 'data-swc-event=', $fallback );
	}

	public function test_month_parameter_is_validated_and_only_applies_to_the_targeted_view(): void {
		$view   = $this->view( array( $this->source ), 'month' );
		$output = new CalendarOutput( $this->services );

		$_GET = array(
			'swc_view'  => (string) $view,
			'swc_month' => '2026-12',
		);
		$this->assertStringContainsString( 'data-swc-month="2027-01"', $output->render( array( 'id' => $view ) ) );

		$_GET['swc_month'] = '2026-13';
		$this->assertStringContainsString( 'data-swc-month="2026-11"', $output->render( array( 'id' => $view ) ), 'Invalid month falls back to now' );

		$_GET = array(
			'swc_view'  => (string) ( $view + 1 ),
			'swc_month' => '2026-12',
		);
		$this->assertStringContainsString( 'data-swc-month="2026-11"', $output->render( array( 'id' => $view ) ) );
	}

	public function test_boolean_and_array_attributes_are_normalized_and_invalid_ones_logged_for_editors(): void {
		$view   = $this->view( array( $this->source ) );
		$output = new CalendarOutput( $this->services );
		$html   = $output->render(
			array(
				'id'          => $view,
				'source'      => array( $this->source ),
				'past'        => false,
				'displayMode' => 'cards',
			)
		);
		$this->assertStringContainsString( 'data-swc-event=', $html );

		$this->services->set( 'logger', new \SW\Calendar\Infrastructure\Database\DbLogger( 'debug' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertNotSame( '', $output->render( array( 'id' => $view, 'limit' => 'many' ) ) );
		$codes = wp_list_pluck( $this->services->logger()->recent( 10, 0, 'debug' ), 'code' );
		$this->assertContains( 'shortcode_filter_ignored', $codes );
	}

	public function test_protected_view_prints_placeholder_with_valid_filters_only(): void {
		$protected = $this->source( $this->ics_connection(), Visibility::Authenticated );
		$view      = $this->view( array( $protected ) );
		$html      = ( new CalendarOutput( $this->services ) )->render(
			array(
				'id'    => $view,
				'limit' => '5',
				'sort'  => 'sideways',
			)
		);
		$this->assertStringContainsString( 'swc-dynamic', $html );
		$this->assertStringContainsString( 'data-swc-filters="{&quot;limit&quot;:&quot;5&quot;}"', $html );
	}

	public function test_frontend_assets_are_registered_with_runtime_config(): void {
		wp_deregister_script( CalendarOutput::SCRIPT );
		wp_deregister_style( CalendarOutput::STYLE );
		CalendarOutput::register_assets();

		$script = wp_scripts()->registered[ CalendarOutput::SCRIPT ];
		$this->assertStringEndsWith( 'assets/js/frontend.js', $script->src );
		$inline = implode( '', (array) wp_scripts()->get_data( CalendarOutput::SCRIPT, 'before' ) );
		$this->assertStringContainsString( 'window.swCloudCalendar = ', $inline );
		$this->assertStringContainsString( 'admin-ajax.php', $inline );
		$this->assertTrue( wp_style_is( CalendarOutput::STYLE, 'registered' ) );
	}

	public function test_ajax_nonce_is_empty_for_guests_and_valid_for_users(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static fn () => static function (): void {
				throw new WPDieException( 'done' );
			}
		);

		$this->assertSame( '', $this->nonce_response() );

		wp_set_current_user( self::factory()->user->create() );
		$nonce = $this->nonce_response();
		$this->assertSame( 1, wp_verify_nonce( $nonce, 'wp_rest' ) );
	}

	private function nonce_response(): string {
		ob_start();
		try {
			CalendarOutput::ajax_nonce();
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		$data = json_decode( (string) ob_get_clean(), true );
		return (string) $data['nonce'];
	}
}
