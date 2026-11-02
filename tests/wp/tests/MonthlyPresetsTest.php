<?php

use SW\Calendar\Application\Local\LocalEvent;
use SW\Calendar\Domain\Template\TemplateKind;
use SW\Calendar\Presentation\Admin\TemplateManager;

final class MonthlyPresetsTest extends SWC_TestCase {
	private function preset_view( string $key, int $source ): int {
		$templates = ( new TemplateManager( $this->services ) )->install_preset( $key );
		return $this->services->views()->save( null, 'Grouped', true, array(
			'source_ids' => array( $source ),
			'display_mode' => 'list',
			'container_template_id' => $templates[0]->id,
			'event_template_id' => $templates[1]->id,
			'timezone' => 'Europe/Berlin',
			'html_cache' => false,
		) );
	}

	public function test_month_headings_follow_display_dates_and_years_without_duplicates(): void {
		$source = $this->source( $this->ics_connection() );
		$events = array();
		foreach ( array( '2026-10-31T23:30:00Z', '2026-11-03T10:00:00Z', '2026-12-01T00:00:00Z', '2027-01-03T10:00:00Z' ) as $i => $time ) {
			$start = new DateTimeImmutable( $time );
			$events[] = ( new LocalEvent( (string) $i, 'cal', 'Event ' . $i, $start, $start->modify( 2 === $i ? '+1 day' : '+1 hour' ), allDay: 2 === $i ) )->occurrence( $source );
		}
		$this->services->occurrences()->replaceSnapshot( $source, $events, 1 );
		foreach ( array( 'monthly-list', 'image-right-list' ) as $key ) {
			$view = $this->preset_view( $key, $source );
			$result = $this->services->renderer()->render( $view, array( 'start' => '2026-10-01', 'end' => '2027-02-01', 'navigation' => 'false', 'columns' => 2 ), null );
			$this->assertSame( 4, $result->total );
			$this->assertSame( 1, substr_count( $result->html, 'data-swc-event-month="2026-11"' ) );
			$this->assertSame( 1, substr_count( $result->html, 'data-swc-event-month="2026-12"' ) );
			$this->assertSame( 1, substr_count( $result->html, 'data-swc-event-month="2027-01"' ) );
			$this->assertStringNotContainsString( 'data-swc-event-month="2026-10"', $result->html );
			$this->assertStringContainsString( 'November 2026</h2>', $result->html );
			$this->assertStringContainsString( 'January 2027</h2>', $result->html );
			$this->assertStringContainsString( 'swc-columns-2', $result->html );
			$this->assertStringNotContainsString( 'swc-right-row__media', $result->html, 'No empty image columns or broken images without attachments' );
			$second = $this->services->renderer()->render( $view, array( 'start' => '2026-10-01', 'end' => '2027-02-01', 'limit' => 1, 'page' => 2, 'navigation' => 'false' ), null );
			$this->assertSame( 1, substr_count( $second->html, 'data-swc-event-month="2026-11"' ), 'Each page has its own month heading' );
			$empty = $this->services->renderer()->render( $view, array( 'month' => '2028-01', 'navigation' => 'false' ), null );
			$this->assertStringContainsString( 'swc-empty', $empty->html );
			$this->assertStringNotContainsString( 'swc-month-group', $empty->html );
		}
	}

	public function test_new_presets_compile_install_and_preview_with_images(): void {
		$this->admin();
		foreach ( array( 'monthly-list', 'image-right-list' ) as $key ) {
			$body = array( 'mode' => 'list' );
			foreach ( TemplateKind::cases() as $kind ) {
				$body[ $kind->value ] = array( 'body' => $this->services->presets()->body( $key, $kind ), 'css' => $this->services->presets()->css( $key, $kind ) );
				$this->assertSame( array(), $this->services->compiler()->validate( $body[ $kind->value ]['body'], $kind ) );
			}
			$response = $this->rest( 'POST', '/admin/templates/preview', array(), $body );
			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data();
			$this->assertSame( array(), (array) $data['errors'] );
			$this->assertStringContainsString( 'swc-month-group__title', $data['html'] );
			if ( 'image-right-list' === $key ) {
				$this->assertStringContainsString( 'swc-right-row__media', $data['html'] );
				$this->assertStringContainsString( 'swc-right-row__weekday', $data['html'] );
				$this->assertStringNotContainsString( '<script>alert', $data['html'] );
				$this->assertStringContainsString( '@container', implode( '', (array) $data['css'] ) );
			}
		}
	}

	public function test_classic_list_keeps_flat_layout(): void {
		$source = $this->source( $this->ics_connection() );
		$this->sync( $source );
		$result = $this->services->renderer()->render( $this->view( array( $source ) ), array(), null );
		$this->assertStringNotContainsString( 'swc-month-group', $result->html );
		$this->assertStringContainsString( 'swc-events__item', $result->html );
	}
}
