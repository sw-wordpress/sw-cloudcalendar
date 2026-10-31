<?php

use SW\Calendar\Domain\Job\JobType;
use SW\Calendar\Infrastructure\Database\Schema;
use SW\Calendar\Infrastructure\WordPress\QueueRunner;
use SW\Calendar\Infrastructure\WordPress\Settings;

/**
 * Background queue (scheduling, job handling, failure handling) and the versioned schema.
 */
final class QueueAndSchemaTest extends SWC_TestCase {

	/**
	 * @return array<string, array<string, mixed>> Jobs by type.
	 */
	private function jobs(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'jobs' ) . ' ORDER BY id', ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ $row['job_type'] . ( null === $row['source_id'] ? '' : '#' . $row['source_id'] ) ] = $row;
		}
		return $out;
	}

	public function test_schedule_registration(): void {
		$schedules = QueueRunner::add_schedule( array( 'hourly' => array() ) );
		$this->assertSame( 300, $schedules[ QueueRunner::SCHEDULE ]['interval'] );
		$this->assertArrayHasKey( 'hourly', $schedules );

		QueueRunner::unschedule();
		$this->assertFalse( wp_next_scheduled( QueueRunner::HOOK_TICK ) );
		QueueRunner::ensure_scheduled();
		$tick = wp_next_scheduled( QueueRunner::HOOK_TICK );
		$this->assertIsInt( $tick );
		$this->assertIsInt( wp_next_scheduled( QueueRunner::HOOK_DAILY ) );
		QueueRunner::ensure_scheduled();
		$this->assertSame( $tick, wp_next_scheduled( QueueRunner::HOOK_TICK ), 'Scheduling is idempotent' );
		QueueRunner::unschedule();
		$this->assertFalse( wp_next_scheduled( QueueRunner::HOOK_DAILY ) );
	}

	public function test_tick_schedules_due_sources_and_processes_sync_jobs(): void {
		$source = $this->source( $this->ics_connection() );
		$this->http->push( 200, $this->feed(), array( 'ETag' => '"v1"' ) );
		delete_option( QueueRunner::LAST_TICK_OPTION );

		( new QueueRunner( $this->services ) )->cron_tick();

		$this->assertGreaterThan( 0, (int) get_option( QueueRunner::LAST_TICK_OPTION ) );
		$this->assertSame( 'done', $this->jobs()[ 'sync_source#' . $source ]['status'] );
		$this->assertGreaterThan( 0, $this->services->occurrences()->count_for_source( $source ) );
	}

	public function test_system_cron_mode_skips_wp_cron_tick(): void {
		$this->source( $this->ics_connection() );
		Settings::update( array( 'cron_mode' => 'system' ) );
		delete_option( QueueRunner::LAST_TICK_OPTION );
		( new QueueRunner( $this->services ) )->cron_tick();
		$this->assertFalse( get_option( QueueRunner::LAST_TICK_OPTION ) );
		$this->assertSame( array(), $this->jobs() );
	}

	public function test_schedule_due_filters_by_source_and_skips_disabled_sync(): void {
		$connection = $this->ics_connection();
		$first      = $this->source( $connection );
		$disabled   = $this->source( $connection, \SW\Calendar\Domain\Source\Visibility::Public, array(), array( 'sync_enabled' => false ) );
		$runner     = new QueueRunner( $this->services );
		$this->assertSame( 0, $runner->schedule_due( 999999 ) );
		$this->assertSame( 1, $runner->schedule_due() );
		$this->assertArrayHasKey( 'sync_source#' . $first, $this->jobs() );
		$this->assertArrayNotHasKey( 'sync_source#' . $disabled, $this->jobs() );
	}

	public function test_daily_maintenance_jobs_run_once(): void {
		$runner = new QueueRunner( $this->services );
		$runner->cron_daily();
		$runner->cron_daily();
		$this->services->jobs()->enqueue( JobType::RebuildCache, null, array(), hash( 'sha256', 'rebuild' ), $this->clock->now() );
		$this->services->jobs()->enqueue( JobType::SyncAttachment, 999999, array( 'ref_hash' => 'x' ), hash( 'sha256', 'att' ), $this->clock->now() );
		$this->services->jobs()->enqueue( JobType::SyncSource, null, array(), hash( 'sha256', 'nosource' ), $this->clock->now() );

		$result = $runner->run( 5 );

		$this->assertSame( 0, $result['scheduled'] );
		$this->assertSame( 5, $result['processed'] );
		$this->assertSame( 0, $result['failed'] );
		$jobs = $this->jobs();
		foreach ( array( 'rotate_logs', 'prune_storage', 'rebuild_cache', 'sync_attachment#999999', 'sync_source' ) as $key ) {
			$this->assertSame( 'done', $jobs[ $key ]['status'], $key );
		}
		$this->assertSame( array( 'scheduled' => 0, 'processed' => 0, 'failed' => 0 ), $runner->run( 5 ) );
	}

	public function test_crashing_job_fails_without_retry_and_is_logged(): void {
		// A broken service makes the handler throw a non-calendar error.
		$this->services->set( 'cache', new stdClass() );
		$this->services->jobs()->enqueue( JobType::RebuildCache, null, array(), hash( 'sha256', 'rebuild' ), $this->clock->now() );
		$result = ( new QueueRunner( $this->services ) )->run( 5 );
		$this->assertSame( 1, $result['failed'] );
		$job = $this->jobs()['rebuild_cache'];
		$this->assertSame( 'failed', $job['status'] );
		$this->assertStringStartsWith( 'UNEXPECTED: TypeError', $job['last_error'] );
		$log = $this->services->logger()->recent( 1 )[0];
		$this->assertSame( 'job_crashed', $log['code'] );
		$this->assertSame( 'rebuild_cache', $log['context']['job_type'] );
	}

	public function test_schema_tables_and_migration_state(): void {
		$this->assertCount( 14, Schema::tables() );
		$this->assertStringEndsWith( 'swc_jobs', Schema::table( 'jobs' ) );
		$this->assertFalse( Schema::needs_migration() );

		delete_option( Schema::VERSION_OPTION );
		$this->assertTrue( Schema::needs_migration() );
		// dbDelta against the installed tables is a no-op; the version is stored again afterwards.
		$this->assertTrue( Schema::migrate() );
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::VERSION_OPTION ) );
	}

	public function test_migration_does_not_store_version_while_a_table_is_missing(): void {
		$probe = static function ( string $query ): string {
			return str_starts_with( $query, 'SELECT 1 FROM' ) && str_contains( $query, 'swc_audit' ) ? 'SELECT 1 FROM `swc_no_such_table` LIMIT 0' : $query;
		};
		add_filter( 'query', $probe );
		delete_option( Schema::VERSION_OPTION );
		try {
			$this->assertSame( array( Schema::table( 'audit' ) ), Schema::missing_tables() );
			$this->assertFalse( Schema::migrate() );
			$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		} finally {
			remove_filter( 'query', $probe );
		}
		$this->assertSame( array(), Schema::missing_tables() );
	}

	public function test_unknown_table_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		Schema::table( 'users' );
	}

	public function test_drop_all_removes_version(): void {
		// The test suite turns DROP TABLE into DROP TEMPORARY TABLE, so the installed tables survive.
		Schema::drop_all();
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( array(), Schema::missing_tables() );
	}
}
