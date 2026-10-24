<?php

use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Infrastructure\WordPress\QueueRunner;
use SW\Calendar\Presentation\Admin\SourceManager;
use SW\Calendar\Presentation\Rest\PublicController;

/**
 * Attachment pipeline end to end with a fake provider HTTP layer (E2E-05/06/07/13, SPECIFICATIONS.md §8).
 */
final class AttachmentTest extends SWC_TestCase {

	private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x35\x81\x84\x00\x00\x00\x00IEND\xaeB`\x82";

	private function attachment_feed(): string {
		return $this->feed( 'attachments.ics' );
	}

	/**
	 * Serves the feed and the files by URL.
	 */
	private function serve( string $feed ): void {
		$files = array(
			'https://files.example.org/plakat.png'   => self::PNG,
			'https://files.example.org/programm.pdf' => "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n",
			'https://files.example.org/fake.pdf'     => '<html><script>alert(1)</script></html>',
			'https://files.example.org/logo.svg'     => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
		);
		for ( $i = 0; $i < 20; $i++ ) {
			$this->http->queue[] = static function ( $request ) use ( $feed, $files ) {
				$body = $files[ $request->url ] ?? $feed;
				return new \SW\Calendar\Application\Http\HttpResponse( 200, array( 'content-type' => 'application/octet-stream' ), $body, strlen( $body ) );
			};
		}
	}

	private function attachment_source( bool $public_only, Visibility $visibility = Visibility::Authenticated ): int {
		return $this->source(
			$this->ics_connection(),
			$visibility,
			array(),
			array(
				'attachments' => array(
					'sync_attachments'   => true,
					'public_only'        => $public_only,
					'allowed_mime_types' => array( 'image/png', 'application/pdf' ),
				),
			)
		);
	}

	private function run_sync_and_jobs( int $source, bool $full = false ): void {
		$this->assertSame( 'success', $this->services->sync()->sync( $source, $full )->status );
		( new QueueRunner( $this->services ) )->run( 20 );
	}

	/**
	 * @return array<string, \SW\Calendar\Domain\Attachment\Attachment>
	 */
	private function attachments_by_name( int $source ): array {
		global $wpdb;
		$ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . \SW\Calendar\Infrastructure\Database\Schema::table( 'attachments' ) . ' WHERE source_id = %d', $source ) );
		$result = array();
		foreach ( $ids as $id ) {
			$attachment                         = $this->services->attachments()->find( (int) $id );
			$result[ $attachment->providerId ] = $attachment;
		}
		return $result;
	}

	public function test_files_are_stored_checked_and_delivered_only_with_source_access(): void {
		$this->serve( $this->attachment_feed() );
		$source = $this->attachment_source( false );
		$this->run_sync_and_jobs( $source );
		$files = $this->attachments_by_name( $source );

		$png = $files['https://files.example.org/plakat.png'];
		$pdf = $files['https://files.example.org/programm.pdf'];
		$this->assertSame( 'stored', $png->syncState->value );
		$this->assertTrue( $png->isImage );
		$this->assertSame( 'image/png', $png->mime );
		$this->assertSame( 'stored', $pdf->syncState->value );
		$this->assertSame( 'Programm.pdf', $pdf->filename );
		$this->assertMatchesRegularExpression( '~^[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{64}$~', (string) $pdf->storageKey );
		$this->assertSame( hash( 'sha256', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n" ), $pdf->sha256 );

		// MIME spoofing: HTML named .pdf is rejected by content; SVG is never stored.
		$this->assertSame( 'skipped', $files['https://files.example.org/fake.pdf']->syncState->value );
		$this->assertStringContainsString( 'text/html', (string) $files['https://files.example.org/fake.pdf']->lastError );
		$this->assertNotSame( 'stored', $files['https://files.example.org/logo.svg']->syncState->value );

		$controller = new PublicController( $this->services );
		$this->assertNull( $controller->authorize_attachment( $pdf->id, false, null ), 'Guests cannot download files of an authenticated source' );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertNotNull( $controller->authorize_attachment( $pdf->id, false, $user ) );
		$stream = $this->services->storage()->openReadStream( (string) $pdf->storageKey );
		$this->assertStringStartsWith( '%PDF', (string) stream_get_contents( $stream ) );
		fclose( $stream );

		// Rendered markup contains only local URLs.
		wp_set_current_user( $user );
		$view = $this->view( array( $source ) );
		$html = $this->services->renderer()->renderDetail( $view, $png->occurrenceId, $user )->html;
		$this->assertStringContainsString( '/sw-cloudcalendar/v1/attachments/' . $png->id . '/', $html );
		$this->assertStringNotContainsString( 'files.example.org', $html );
	}

	public function test_public_only_skips_unverifiable_files(): void {
		$this->serve( $this->attachment_feed() );
		$source = $this->attachment_source( true );
		$this->run_sync_and_jobs( $source );
		foreach ( $this->attachments_by_name( $source ) as $attachment ) {
			$this->assertSame( 'skipped', $attachment->syncState->value );
			$this->assertSame( 'UNKNOWN', $attachment->publicationState->value );
			$this->assertStringStartsWith( 'PUBLIC_STATUS_UNKNOWN', (string) $attachment->lastError );
		}
		$stats = $this->services->attachments()->stats( $source );
		$this->assertContains( 'skipped', array_column( $stats['counts'], 'sync_state' ) );
	}

	public function test_removed_attachment_and_acl_change_revoke_delivery(): void {
		$this->serve( $this->attachment_feed() );
		$source = $this->attachment_source( false );
		$this->run_sync_and_jobs( $source );
		$pdf  = $this->attachments_by_name( $source )['https://files.example.org/programm.pdf'];
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$controller = new PublicController( $this->services );
		$this->assertNotNull( $controller->authorize_attachment( $pdf->id, false, $user ) );

		// ACL change: delivery stops immediately, before any sync.
		$this->admin();
		( new SourceManager( $this->services ) )->save( $source, array( 'visibility' => 'roles', 'roles' => array( 'editor' ) ) );
		$this->assertNull( $controller->authorize_attachment( $pdf->id, false, $user ) );

		// Attachment removed at the provider: revoked after the next successful sync.
		$this->http->queue = array();
		$this->serve( str_replace( "ATTACH;FMTTYPE=application/pdf;FILENAME=Programm.pdf:https://files.example.org/programm.pdf\r\n", '', $this->attachment_feed() ) );
		$this->run_sync_and_jobs( $source, true );
		$this->assertSame( 'revoked', $this->services->attachments()->find( $pdf->id )->syncState->value );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertNull( $controller->authorize_attachment( $pdf->id, false, $editor ) );
	}

	public function test_unsafe_storage_blocks_attachment_sync(): void {
		update_option( \SW\Calendar\Infrastructure\WordPress\StorageGuard::OPTION, array( 'checked_at' => time(), 'safe' => false, 'reason' => 'publicly_accessible', 'root' => SW_CALENDAR_PRIVATE_STORAGE_PATH ) );
		$this->admin();
		$response = $this->rest( 'PUT', '/admin/sources/' . $this->source( $this->ics_connection(), Visibility::Authenticated ), array(), array( 'settings' => array( 'attachments' => array( 'sync_attachments' => true ) ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'storage_unsafe', $response->get_data()['data']['params']['settings.attachments.sync_attachments'] );
	}
}
