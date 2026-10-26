<?php

use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Infrastructure\Database\Schema;

final class SyncAndRenderTest extends SWC_TestCase {

	public function test_ics_sync_stores_occurrences_and_renders_shortcode(): void {
		$source = $this->source( $this->ics_connection() );
		$report = $this->sync( $source );
		$this->assertGreaterThan( 10, $report->metrics->created );
		$this->assertGreaterThan( 1, $this->services->sources()->find( $source )->revision, 'Revision bumped by the data change' );

		$view = $this->view( array( $source ) );
		$html = do_shortcode( '[sw_calendar id="' . $view . '" start="2026-10-01" end="2026-11-30" search="ottesdienst"]' );
		$this->assertStringContainsString( 'Abendgottesdienst', $html );
		$this->assertStringContainsString( 'swc-view-' . $view, $html );
		$this->assertStringNotContainsString( 'swc-dynamic', $html, 'Public views are rendered inline' );
		// Month grid with the DST weekend and exclusive end handling.
		$month_view = $this->view( array( $source ), 'month' );
		$month      = do_shortcode( '[sw_calendar id="' . $month_view . '" month="2026-12"]' );
		$this->assertStringContainsString( '<table class="swc-month">', $month );
	}

	public function test_unchanged_feed_does_not_bump_revision_and_304_keeps_data(): void {
		$source = $this->source( $this->ics_connection() );
		$this->sync( $source );
		$revision = $this->services->sources()->find( $source )->revision;
		$this->http->push( 304, '' );
		$this->clock->advance( '+20 minutes' );
		$report = $this->services->sync()->sync( $source );
		$this->assertSame( 'success', $report->status );
		$this->assertTrue( $report->metrics->notModified );
		$this->assertSame( '"v1"', $this->http->requests[1]->headers['If-None-Match'] );
		$this->assertSame( $revision, $this->services->sources()->find( $source )->revision );
	}

	public function test_provider_outage_keeps_last_data(): void {
		global $wpdb;
		$source = $this->source( $this->ics_connection() );
		$this->sync( $source );
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'occurrences' ) );
		$this->http->push( 503, 'down', array( 'Retry-After' => '600' ) );
		$report = $this->services->sync()->sync( $source );
		$this->assertSame( 'failed', $report->status );
		$this->assertSame( 'RATE_LIMITED', $report->errorCode );
		$this->assertSame( $count, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'occurrences' ) ) );
		$view = $this->view( array( $source ) );
		$this->assertStringContainsString( 'Gottesdienst', do_shortcode( '[sw_calendar id="' . $view . '" start="2026-10-01" end="2026-11-30"]' ) );
	}

	public function test_deleted_event_disappears_after_successful_sync(): void {
		$source = $this->source( $this->ics_connection() );
		$this->sync( $source );
		$feed = preg_replace( '/BEGIN:VEVENT\r\nUID:floating-1@test.*?END:VEVENT\r\n/s', '', $this->feed() );
		$this->http->push( 200, (string) $feed, array( 'ETag' => '"v2"' ) );
		$this->assertSame( 'success', $this->services->sync()->sync( $source, true )->status );
		$view = $this->view( array( $source ) );
		$this->assertStringNotContainsString( 'Konzert', do_shortcode( '[sw_calendar id="' . $view . '" start="2026-11-01" end="2026-11-30"]' ) );
	}

	public function test_protected_view_is_never_serialized_into_page_html(): void {
		$source = $this->source( $this->ics_connection(), Visibility::Roles, array( 'editor' ) );
		$this->sync( $source );
		$view = $this->view( array( $source ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$html = do_shortcode( '[sw_calendar id="' . $view . '"]' );
		$this->assertStringContainsString( 'swc-dynamic', $html );
		$this->assertStringNotContainsString( 'Gottesdienst', $html );
		// Invalid attributes are not forwarded to the strict REST endpoint.
		$html = do_shortcode( '[sw_calendar id="' . $view . '" limit="5000" days="7"]' );
		$this->assertStringContainsString( '{&quot;days&quot;:&quot;7&quot;}', $html );
	}

	public function test_template_ast_cache_hit_and_html_cache_hit(): void {
		$source = $this->source( $this->ics_connection() );
		$this->sync( $source );
		$view     = $this->view( array( $source ) );
		$renderer = $this->services->renderer();
		$first    = $renderer->render( $view, array( 'start' => '2026-10-01', 'end' => '2026-11-30' ), null );
		$this->assertFalse( $first->cacheHit );
		$second = $renderer->render( $view, array( 'start' => '2026-10-01', 'end' => '2026-11-30' ), null );
		$this->assertTrue( $second->cacheHit );
		$this->assertSame( $first->html, $second->html );
		$this->assertGreaterThan( 0, $this->services->template_provider()->cacheMisses );
	}
}
