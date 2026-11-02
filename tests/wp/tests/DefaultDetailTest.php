<?php

use SW\Calendar\Application\Local\LocalEvent;
use SW\Calendar\Application\Render\Component\AttachmentRenderer;
use SW\Calendar\Application\Render\EventValues;
use SW\Calendar\Application\Render\PreviewData;
use SW\Calendar\Application\Render\Template\TemplateRenderer;
use SW\Calendar\Application\Render\ViewRenderer;
use SW\Calendar\Domain\Attachment\Attachment;
use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\Attachment\SyncState;
use SW\Calendar\Domain\Template\TemplateKind;

final class DefaultDetailTest extends SWC_TestCase {
	private function values(): EventValues {
		return new EventValues( $this->services->dates(), $this->services->urls(), $this->services->sanitizer(), new AttachmentRenderer( $this->services->urls(), $this->services->labels() ), $this->services->labels() );
	}

	public function test_all_default_details_preserve_images_files_and_event_metadata(): void {
		$tz = new DateTimeZone( 'Europe/Berlin' );
		$data = PreviewData::build( $this->clock->now(), $tz );
		$item = $data['items'][0];
		$images = $item['attachments'];
		$images[] = new Attachment( 41, 1, 101, 'second', 'second', 'Second.png', 'image/png', 20, null, null, 'stored', null, true, PublicationState::PublicVerified, SyncState::Stored );
		$map = $this->values()->build( $item['occurrence'], $data['sources'][1], $images, 12, $tz, 'd.m.Y', 'H:i', true, true );
		$this->assertTrue( $map['event.has_multiple_images'] );
		foreach ( $this->services->presets()->keys() as $key ) {
			$compiled = $this->services->compiler()->compile( $this->services->presets()->body( $key, TemplateKind::Detail ), 'detail' );
			$html = ( new TemplateRenderer() )->render( $compiled, $map );
			$this->assertStringContainsString( 'swc-event-detail__main', $html );
			$this->assertStringContainsString( 'swc-event-detail__sidebar', $html );
			$this->assertStringContainsString( 'swc-event-detail__image-link', $html );
			$this->assertStringContainsString( 'View image', $html );
			$this->assertStringContainsString( 'Date</h3>', $html );
			$this->assertStringContainsString( 'Time</h3>', $html );
			$this->assertStringContainsString( 'Kirche St. Martin', $html );
			$this->assertStringContainsString( 'Programm.pdf', $html );
			$this->assertSame( 2, substr_count( $html, 'class="swc-gallery__item"' ) );
			$this->assertStringContainsString( 'noopener', $html );
		}
	}

	public function test_formal_german_uses_bundled_labels_when_no_formal_catalog_exists(): void {
		$locale = static fn (): string => 'de_DE_formal';
		add_filter( 'determine_locale', $locale );
		try {
			\SW\Calendar\Plugin::instance()->load_textdomain();
			$this->assertSame( 'Heute', __( 'Today', 'sw-cloudcalendar' ) );
			$this->assertSame( 'Datum', _x( 'Date', 'event details', 'sw-cloudcalendar' ) );
			$this->assertSame( 'Bild anzeigen', __( 'View image', 'sw-cloudcalendar' ) );
		} finally {
			remove_filter( 'determine_locale', $locale );
			unload_textdomain( 'sw-cloudcalendar', true );
		}
	}

	public function test_all_day_plain_description_and_image_visibility(): void {
		$start = new DateTimeImmutable( '2026-11-03T00:00:00Z' );
		$event = new LocalEvent( 'all-day', 'cal', '<script>Title</script>', $start, $start->modify( '+1 day' ), allDay: true, descriptionText: "First line\n<script>plain</script>", imageUrl: 'https://example.org/photo.png' );
		$map = $this->values()->build( $event->occurrence( 1 ), null, array(), 12, new DateTimeZone( 'Europe/Berlin' ), 'd.m.Y', 'H:i', false, true );
		$html = ( new TemplateRenderer() )->render( $this->services->compiler()->compile( $this->services->presets()->body( 'classic-list', TemplateKind::Detail ), 'detail' ), $map );
		$this->assertStringContainsString( 'All day', $html );
		$this->assertStringContainsString( '03.11.2026', $html );
		$this->assertStringNotContainsString( '04.11.2026', $html );
		$this->assertStringContainsString( 'First line', $html );
		$this->assertStringContainsString( '&lt;script&gt;plain&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertFalse( $map['event.has_multiple_images'] );
		$this->assertSame( '#ffffff', ViewRenderer::textColor( '#000000' ) );
		$this->assertSame( '#000000', ViewRenderer::textColor( '#ffffff' ) );
		$this->assertSame( '#000000', ViewRenderer::textColor( '#4a86e8' ) );
	}
}
