<?php

use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Infrastructure\Database\DbLogger;
use SW\Calendar\Infrastructure\Database\Schema;
use SW\Calendar\Infrastructure\WordPress\Capabilities;
use SW\Calendar\Infrastructure\WordPress\Maintenance;
use SW\Calendar\Infrastructure\WordPress\QueueRunner;
use SW\Calendar\Infrastructure\WordPress\Settings;
use SW\Calendar\Infrastructure\WordPress\StorageGuard;
use SW\Calendar\Infrastructure\WordPress\TransientCache;
use SW\Calendar\Infrastructure\WordPress\WpImagePreview;
use SW\Calendar\Lifecycle;
use SW\Calendar\Presentation\Rest\ErrorMessages;

/**
 * Settings, cache, log, maintenance, lifecycle hooks, image previews and error texts against real WordPress.
 */
final class InfrastructureServicesTest extends SWC_TestCase {

	public function tear_down(): void {
		Capabilities::grant_defaults();
		QueueRunner::unschedule();
		parent::tear_down();
	}

	public function test_services_share_instances_and_accept_compatible_replacements(): void {
		$cache = $this->services->cache();
		$this->assertSame( $cache, $this->services->cache() );

		$replacement = new TransientCache();
		$this->services->set( 'cache', $replacement );
		$this->assertSame( $replacement, $this->services->cache() );
		$this->assertSame( $this->http, $this->services->http() );
		$this->assertSame( $this->clock, $this->services->clock() );
	}

	public function test_every_error_code_has_a_message_and_fetch_failures_keep_the_detail(): void {
		foreach ( ErrorCode::cases() as $code ) {
			$this->assertNotSame( '', ErrorMessages::for( $code ), $code->value );
		}
		$this->assertSame( 'Feed returned 500', ErrorMessages::for( ErrorCode::FetchFailed, 'Feed returned 500' ) );
		$this->assertSame( 'The provider could not be reached.', ErrorMessages::for( ErrorCode::FetchFailed ) );
		$this->assertSame( 'Invalid input.', ErrorMessages::for( ErrorCode::InvalidInput, 'ignored' ) );
	}

	public function test_settings_defaults_validation_and_partial_updates(): void {
		delete_option( Settings::OPTION );
		$this->assertSame( Settings::defaults(), Settings::all() );
		$this->assertSame( 'info', Settings::get( 'log_level' ) );
		$this->assertNull( Settings::get( 'nope' ) );

		$errors = Settings::update(
			array(
				'unknown'            => 1,
				'log_retention_days' => 0,
				'public_rate_limit'  => 'many',
				'cron_mode'          => 'hourly',
				'log_level'          => 'trace',
				'default_view_timezone' => 'Mars/Base',
				'html_cache'         => false,
			)
		);
		$this->assertSame(
			array(
				'unknown'               => 'unknown',
				'log_retention_days'    => 'out_of_range',
				'public_rate_limit'     => 'out_of_range',
				'cron_mode'             => 'invalid',
				'log_level'             => 'invalid',
				'default_view_timezone' => 'invalid',
			),
			$errors
		);
		$this->assertTrue( Settings::get( 'html_cache' ), 'Nothing is stored when any field is invalid' );

		$this->assertSame(
			array(),
			Settings::update(
				array(
					'html_cache'               => 0,
					'delete_data_on_uninstall' => 'yes',
					'cron_mode'                => 'system',
					'log_level'                => 'debug',
					'log_retention_days'       => '3650',
					'revoked_file_grace_hours' => 0,
					'default_view_timezone'    => 'America/New_York',
				)
			)
		);
		$all = Settings::all();
		$this->assertFalse( $all['html_cache'] );
		$this->assertTrue( $all['delete_data_on_uninstall'] );
		$this->assertSame( 'system', $all['cron_mode'] );
		$this->assertSame( 3650, $all['log_retention_days'] );
		$this->assertSame( 0, $all['revoked_file_grace_hours'] );
		$this->assertSame( 2048, $all['global_quota_mb'], 'Untouched values keep their default' );
		$this->assertSame( 'America/New_York', Settings::site_timezone() );

		update_option( Settings::OPTION, 'corrupt' );
		$this->assertSame( Settings::defaults(), Settings::all() );
	}

	public function test_site_timezone_falls_back_to_utc_for_manual_offsets(): void {
		Settings::update( array( 'default_view_timezone' => '' ) );
		$this->assertSame( 'Europe/Berlin', Settings::site_timezone() );
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 2 );
		$this->assertSame( 'UTC', Settings::site_timezone() );
	}

	public function test_transient_cache_round_trip_generation_and_stats(): void {
		delete_option( TransientCache::GENERATION_OPTION );
		delete_option( TransientCache::STATS_OPTION );
		$cache = new TransientCache( 'swc_test' );
		$this->assertSame( 1, $cache->generation() );
		$this->assertNull( $cache->get( 'k' ) );
		$cache->set( 'k', 'v', 0 );
		$this->assertSame( 'v', $cache->get( 'k' ) );
		$cache->delete( 'k' );
		$this->assertNull( $cache->get( 'k' ) );

		$cache->set( 'k', 'v', 60 );
		$cache->clear();
		$this->assertSame( 2, $cache->generation() );
		$this->assertSame( 2, (int) get_option( TransientCache::GENERATION_OPTION ) );
		$this->assertNull( $cache->get( 'k' ), 'Clearing invalidates every key' );
		$this->assertNull( ( new TransientCache( 'swc_test' ) )->get( 'k' ), 'Other instances see the new generation' );

		$cache->flush_stats();
		$stats = get_option( TransientCache::STATS_OPTION );
		$this->assertSame( 1, $stats['hits'] );
		$this->assertSame( 3, $stats['misses'] );
		$since = $stats['since'];

		// Nothing counted since the flush: the option stays as it is.
		$cache->flush_stats();
		$this->assertSame( $stats, get_option( TransientCache::STATS_OPTION ) );

		$cache->get( 'missing' );
		$cache->flush_stats();
		$stats = get_option( TransientCache::STATS_OPTION );
		$this->assertSame( 4, $stats['misses'] );
		$this->assertSame( $since, $stats['since'] );

		update_option( TransientCache::STATS_OPTION, 'corrupt' );
		$cache->get( 'missing' );
		$cache->flush_stats();
		$this->assertSame( 1, get_option( TransientCache::STATS_OPTION )['misses'] );
		$this->assertNotFalse( has_action( 'shutdown', array( $cache, 'flush_stats' ) ) );
	}

	public function test_logger_filters_levels_redacts_and_pages(): void {
		$logger = new DbLogger( 'warning' );
		$before      = $logger->count();
		$warn_before = $logger->count( 'warning' );
		$logger->log( 'info', 'ignored', 'below the minimum level' );
		$this->assertSame( $before, $logger->count() );

		$logger->log( 'warning', 'sync_slow', 'slow', array( 'source_id' => '7', 'url' => 'https://x.test/?token=abc', 'n' => 3 ) );
		$logger->log( 'error', 'sync_failed', 'failed', array( 'source_id' => 8 ) );
		$logger->log( 'custom', 'odd_level', 'unknown levels are treated as errors' );

		$this->assertSame( $before + 3, $logger->count() );
		$this->assertSame( $warn_before + 1, $logger->count( 'warning' ) );
		$this->assertSame( 1, $logger->count( null, 7 ) );
		$this->assertSame( 1, $logger->count( 'error', 8 ) );
		$this->assertSame( $logger->count(), $logger->count( 'bogus' ), 'Unknown filter levels are ignored' );

		$recent = $logger->recent( 2 );
		$this->assertCount( 2, $recent );
		$this->assertSame( 'odd_level', $recent[0]['code'] );
		$this->assertNull( $recent[0]['source_id'] );
		$this->assertSame( 'sync_failed', $recent[1]['code'] );
		$this->assertSame( 8, $recent[1]['source_id'] );

		$slow = $logger->recent( 10, 0, 'warning', 7 );
		$this->assertCount( 1, $slow );
		$this->assertSame( 3, $slow[0]['context']['n'] );
		$this->assertStringNotContainsString( 'abc', (string) wp_json_encode( $slow[0]['context'] ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT/', (string) $slow[0]['created_at'] );
		$this->assertSame( 'sync_failed', $logger->recent( 1, 1 )[0]['code'] );
	}

	public function test_logger_purge_by_age_and_row_limit(): void {
		global $wpdb;
		$table  = Schema::table( 'logs' );
		$logger = new DbLogger( 'debug' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore
		$logger->log( 'debug', 'old', 'old' );
		$wpdb->query( "UPDATE {$table} SET created_at = '2020-01-01 00:00:00'" ); // phpcs:ignore
		for ( $i = 0; $i < 4; $i++ ) {
			$logger->log( 'info', 'new' . $i, 'new' );
		}
		$this->assertSame( 1, $logger->purge( new DateTimeImmutable( '2021-01-01' ) ) );
		$this->assertSame( 4, $logger->count() );
		$this->assertSame( 2, $logger->purge( new DateTimeImmutable( '2021-01-01' ), 2 ) );
		$this->assertSame( array( 'new3', 'new2' ), array_column( $logger->recent( 10 ), 'code' ) );
	}

	public function test_maintenance_rotates_logs_and_records_the_run(): void {
		global $wpdb;
		$table = Schema::table( 'logs' );
		$this->services->logger()->log( 'error', 'ancient', 'x' );
		$wpdb->query( "UPDATE {$table} SET created_at = '2000-01-01 00:00:00'" ); // phpcs:ignore

		$result = ( new Maintenance( $this->services ) )->rotate();

		$this->assertSame( array( 'logs', 'runs', 'jobs', 'stale' ), array_keys( $result ) );
		$this->assertGreaterThanOrEqual( 1, $result['logs'] );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_code = 'ancient'" ) ); // phpcs:ignore
		$this->assertGreaterThan( 0, (int) get_option( QueueRunner::LAST_MAINTENANCE_OPTION ) );
	}

	public function test_maintenance_prunes_unreferenced_files_after_the_grace_period(): void {
		global $wpdb;
		$storage = $this->services->storage();
		$keys    = array();
		foreach ( array( 'revoked', 'stored', 'invalid' ) as $name ) {
			if ( 'invalid' === $name ) {
				$keys[ $name ] = '../escape';
				continue;
			}
			$temp = $storage->temporaryFile();
			file_put_contents( $temp, $name ); // phpcs:ignore
			$keys[ $name ] = $storage->newKey();
			$storage->putFromTemp( $temp, $keys[ $name ] );
		}
		$preview_temp = $storage->temporaryFile();
		file_put_contents( $preview_temp, 'p' ); // phpcs:ignore
		$preview = $storage->newKey( 'p' );
		$storage->putFromTemp( $preview_temp, $preview );

		$source = $this->source( $this->ics_connection() );
		$rows   = array(
			array( $keys['revoked'], $preview, 'revoked' ),
			array( $keys['stored'], null, 'stored' ),
			array( $keys['invalid'], null, 'skipped' ),
		);
		foreach ( $rows as $i => $row ) {
			$wpdb->insert(
				Schema::table( 'attachments' ),
				array(
					'source_id'         => $source,
					'occurrence_id'     => 1000 + $i,
					'provider_ref_hash' => hash( 'sha256', (string) $i ),
					'ref_kind'          => 'url',
					'provider_id'       => 'p' . $i,
					'provider_event_id' => 'e' . $i,
					'filename'          => 'f.pdf',
					'mime'              => 'application/pdf',
					'storage_key'       => $row[0],
					'preview_key'       => $row[1],
					'publication_state' => 'public',
					'sync_state'        => $row[2],
					'updated_at'        => '2026-10-20 00:00:00',
				)
			);
		}
		// A stale temporary download is removed as well.
		$stale = $storage->temporaryFile();
		file_put_contents( $stale, 'x' ); // phpcs:ignore
		touch( $stale, time() - 2 * DAY_IN_SECONDS );

		Settings::update( array( 'revoked_file_grace_hours' => 24 ) );
		$maintenance = new Maintenance( $this->services );
		$dry         = $maintenance->prune_storage( true );
		$this->assertSame( 0, $dry['deleted'] );
		$this->assertSame( 0, $dry['temporary'] );
		$this->assertEqualsCanonicalizing( array( $keys['revoked'], $keys['invalid'] ), $dry['keys'] );
		$this->assertNotNull( $storage->size( $keys['revoked'] ) );

		$result = $maintenance->prune_storage();
		$this->assertSame( 2, $result['deleted'] );
		$this->assertSame( array(), $result['keys'] );
		$this->assertSame( 1, $result['temporary'] );
		$this->assertNull( $storage->size( $keys['revoked'] ) );
		$this->assertNull( $storage->size( $preview ) );
		$this->assertNotNull( $storage->size( $keys['stored'] ), 'Referenced files stay' );
		$this->assertFileDoesNotExist( $stale );
		$this->assertSame( array(), $this->services->attachments()->unreferencedStorageKeys( new DateTimeImmutable( '2030-01-01' ), 10 ) );
	}

	public function test_activation_and_deactivation_manage_schedule_caps_and_presets(): void {
		QueueRunner::unschedule();
		get_role( 'administrator' )->remove_cap( Capabilities::MANAGE );

		Lifecycle::activate( true );

		$this->assertNotFalse( wp_next_scheduled( QueueRunner::HOOK_TICK ) );
		$this->assertNotFalse( wp_next_scheduled( QueueRunner::HOOK_DAILY ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertSame( array(), Schema::missing_tables() );
		$this->assertGreaterThanOrEqual( 12, $this->services->templates()->count() );

		Lifecycle::deactivate();
		$this->assertFalse( wp_next_scheduled( QueueRunner::HOOK_TICK ) );
		$this->assertFalse( wp_next_scheduled( QueueRunner::HOOK_DAILY ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ), 'Deactivation keeps data and rights' );
	}

	public function test_uninstall_keeps_data_unless_requested(): void {
		QueueRunner::ensure_scheduled();
		update_option( TransientCache::GENERATION_OPTION, 5 );

		Lifecycle::uninstall();

		$this->assertFalse( wp_next_scheduled( QueueRunner::HOOK_TICK ) );
		$this->assertSame( 5, (int) get_option( TransientCache::GENERATION_OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
	}

	public function test_uninstall_with_data_removal_revokes_tokens_and_deletes_options(): void {
		$google = $this->services->connections()->create( Provider::Google, 'G', AuthMode::OAuth, ConnectionStatus::Connected, array() );
		$this->services->credentials()->update( $google, array( 'refresh_token' => 'refresh-1' ) );
		$failing = $this->services->connections()->create( Provider::Microsoft, 'M', AuthMode::OAuth, ConnectionStatus::Connected, array() );
		$this->services->credentials()->update( $failing, array( 'refresh_token' => 'refresh-2' ) );
		$this->ics_connection();
		Settings::update( array( 'delete_data_on_uninstall' => true ) );
		foreach ( array( StorageGuard::OPTION, TransientCache::GENERATION_OPTION, TransientCache::STATS_OPTION, QueueRunner::LAST_TICK_OPTION, QueueRunner::LAST_MAINTENANCE_OPTION ) as $option ) {
			update_option( $option, 1 );
		}
		$this->http->push( 200, '' );
		// The second provider call fails hard; uninstall must continue anyway.
		$this->http->queue[] = static function (): never {
			throw new RuntimeException( 'provider down' );
		};

		Lifecycle::uninstall();

		$revokes = array_values( array_filter( $this->http->requests, static fn ( $r ): bool => 'POST' === $r->method ) );
		$this->assertNotEmpty( $revokes );
		$this->assertStringContainsString( 'token=refresh-1', (string) $revokes[0]->body );
		foreach ( array( Settings::OPTION, StorageGuard::OPTION, TransientCache::GENERATION_OPTION, TransientCache::STATS_OPTION, QueueRunner::LAST_TICK_OPTION, QueueRunner::LAST_MAINTENANCE_OPTION, Schema::VERSION_OPTION ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
	}

	public function test_image_preview_downscales_large_images_to_the_exact_target(): void {
		$dir    = get_temp_dir() . 'swc-preview-' . wp_rand();
		$source = $dir . '/big.png';
		wp_mkdir_p( $dir );
		$image = imagecreatetruecolor( 2048, 1024 );
		imagepng( $image, $source );
		imagedestroy( $image );

		$preview = new WpImagePreview();
		$this->assertTrue( $preview->createPreview( $source, $dir . '/out', 'image/png' ) );
		$this->assertFileExists( $dir . '/out' );
		$size = getimagesize( $dir . '/out' );
		$this->assertSame( array( 1024, 512 ), array( $size[0], $size[1] ) );

		$small = $dir . '/small.jpg';
		$image = imagecreatetruecolor( 20, 10 );
		imagejpeg( $image, $small );
		imagedestroy( $image );
		$this->assertTrue( $preview->createPreview( $small, $dir . '/small-out', 'image/jpeg' ) );
		$this->assertSame( 20, getimagesize( $dir . '/small-out' )[0] );

		$this->remove( $dir );
	}

	public function test_image_preview_rejects_invalid_and_animated_images(): void {
		$dir = get_temp_dir() . 'swc-preview-' . wp_rand();
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/fake.png', 'not an image' ); // phpcs:ignore
		$preview = new WpImagePreview();
		$this->assertFalse( $preview->createPreview( $dir . '/fake.png', $dir . '/o1', 'image/png' ) );

		// Three frames with graphic control extensions (the heuristic counts those after a block terminator).
		$frame    = "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";
		$animated = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF" . $frame . $frame . $frame . "\x3B";
		file_put_contents( $dir . '/anim.gif', $animated ); // phpcs:ignore
		$this->assertNotFalse( @getimagesize( $dir . '/anim.gif' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$this->assertFalse( $preview->createPreview( $dir . '/anim.gif', $dir . '/o2', 'image/gif' ) );
		$this->assertFileDoesNotExist( $dir . '/o2' );

		// A still GIF is re-encoded.
		$still = imagecreatetruecolor( 4, 4 );
		imagegif( $still, $dir . '/still.gif' );
		imagedestroy( $still );
		$this->assertTrue( $preview->createPreview( $dir . '/still.gif', $dir . '/o3', 'image/gif' ) );

		// No editor supports the requested type.
		add_filter( 'wp_image_editors', '__return_empty_array' );
		try {
			$this->assertFalse( $preview->createPreview( $dir . '/still.gif', $dir . '/o4', 'image/gif' ) );
		} finally {
			remove_filter( 'wp_image_editors', '__return_empty_array' );
		}
		$this->remove( $dir );
	}

	private function remove( string $dir ): void {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			wp_delete_file( (string) $file );
		}
		rmdir( $dir ); // phpcs:ignore
	}
}
