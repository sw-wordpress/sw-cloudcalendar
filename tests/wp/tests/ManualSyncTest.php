<?php

use SW\Calendar\Domain\Job\JobType;
use SW\Calendar\Infrastructure\Database\Schema;

final class ManualSyncTest extends SWC_TestCase {
	public function set_up(): void {
		parent::set_up();
		$this->admin();
	}

	public function test_sync_executes_only_requested_source_and_completes_pending_job(): void {
		$id = $this->source( $this->ics_connection() );
		$other = $this->source( $this->ics_connection() );
		$now = $this->clock->now();
		$jobs = $this->services->jobs();
		$jobs->enqueue( JobType::SyncSource, $other, array(), hash( 'sha256', 'sync_source|' . $other ), $now );
		$jobs->enqueue( JobType::SyncSource, $id, array( 'full' => true ), hash( 'sha256', 'sync_source|' . $id ), $now->modify( '+1 hour' ) );
		$jobs->enqueue( JobType::SyncAttachment, $id, array( 'ref_hash' => 'unrelated' ), 'unrelated', $now );
		$this->http->push( 200, $this->feed() );
		$response = $this->rest( 'POST', '/admin/sources/' . $id . '/sync-now' );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertFalse( $response->get_data()['sync_queued'] );
		$this->assertNotNull( $response->get_data()['last_success_at'] );
		$this->assertGreaterThan( 0, $response->get_data()['occurrence_count'] );
		$this->assertTrue( $jobs->has_pending( JobType::SyncSource, $other ) );
		$this->assertTrue( $jobs->has_pending( JobType::SyncAttachment, $id ) );
		$this->assertSame( 'full', $this->services->sync_runs()->recent( $id, 1 )[0]['mode'] );
	}

	public function test_manual_sync_does_not_overlap_a_claimed_job(): void {
		$id = $this->source( $this->ics_connection() );
		$now = $this->clock->now();
		$jobs = $this->services->jobs();
		$jobs->enqueue( JobType::SyncSource, $id, array(), hash( 'sha256', 'sync_source|' . $id ), $now );
		$this->assertNotNull( $jobs->claim( $now, $now->modify( '+10 minutes' ), $id ) );
		$this->assertSame( 409, $this->rest( 'POST', '/admin/sources/' . $id . '/sync-now' )->get_status() );
		$this->assertCount( 0, $this->services->sync_runs()->recent( $id, 10 ) );
	}

	public function test_manual_sync_respects_source_lock_and_leaves_job_for_retry(): void {
		$id = $this->source( $this->ics_connection() );
		$now = $this->clock->now();
		$this->services->sync_state()->acquireLock( $id, $now, $now->modify( '+10 minutes' ) );
		$this->assertSame( 409, $this->rest( 'POST', '/admin/sources/' . $id . '/sync-now' )->get_status() );
		$this->assertTrue( $this->services->jobs()->has_pending( JobType::SyncSource, $id ) );
	}

	public function test_sync_failure_is_not_reported_as_success(): void {
		$id = $this->source( $this->ics_connection() );
		$this->http->push( 503, 'Unavailable' );
		$this->assertSame( 502, $this->rest( 'POST', '/admin/sources/' . $id . '/sync-now' )->get_status() );
		$this->assertSame( 'failed', $this->services->sync_runs()->recent( $id, 1 )[0]['status'] );
		$this->assertFalse( $this->services->jobs()->has_pending( JobType::SyncSource, $id ) );
		$this->assertNotNull( $this->services->sync_state()->load( $id )->nextAttemptAt );
	}

	public function test_manual_sync_rejects_missing_paused_and_local_sources_and_requires_admin(): void {
		$this->assertSame( 404, $this->rest( 'POST', '/admin/sources/99999/sync-now' )->get_status() );
		$id = $this->source( $this->ics_connection() );
		$this->rest( 'PUT', '/admin/sources/' . $id, array(), array( 'status' => 'paused' ) );
		$this->assertSame( 409, $this->rest( 'POST', '/admin/sources/' . $id . '/sync-now' )->get_status() );
		$local = $this->services->connections()->create( \SW\Calendar\Domain\Connection\Provider::Local, 'Local', \SW\Calendar\Domain\Connection\AuthMode::None, \SW\Calendar\Domain\Connection\ConnectionStatus::Connected, array( 'adapter' => 'absent' ) );
		$local_id = $this->source( $local );
		$this->assertSame( 409, $this->rest( 'POST', '/admin/sources/' . $local_id . '/sync-now' )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'POST', '/admin/sources/' . $id . '/sync-now' )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->rest( 'POST', '/admin/sources/' . $id . '/sync-now' )->get_status() );
	}
}
