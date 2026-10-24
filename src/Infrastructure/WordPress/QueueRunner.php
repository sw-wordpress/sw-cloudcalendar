<?php
/**
 * Background processing through WP-Cron or a real cron (WP-CLI). Never runs in frontend requests.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Job\Job;
use SW\Calendar\Domain\Job\JobType;
use SW\Calendar\Services;

/**
 * One tick: enqueue sync jobs of due sources (idempotent per source), then process claimed jobs until the deadline.
 * Jobs are leased atomically, so overlapping ticks (WP-Cron + CLI) never process the same job twice; the per source
 * lock of the sync service additionally prevents parallel syncs of one source.
 */
final class QueueRunner {

	public const HOOK_TICK               = 'sw_calendar_cron_tick';
	public const HOOK_DAILY              = 'sw_calendar_cron_daily';
	public const SCHEDULE                = 'sw_calendar_five_minutes';
	public const LAST_TICK_OPTION        = 'sw_calendar_last_tick';
	public const LAST_MAINTENANCE_OPTION = 'sw_calendar_last_maintenance';
	public const MAX_ATTEMPTS            = 6;
	private const LEASE_SECONDS          = 600;

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @param array<string, array<string, mixed>> $schedules
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (SW CloudCalendar)', 'sw-cloudcalendar' ),
		);
		return $schedules;
	}

	public static function ensure_scheduled(): void {
		if ( false === wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK_TICK );
		}
		if ( false === wp_next_scheduled( self::HOOK_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK_TICK );
		wp_clear_scheduled_hook( self::HOOK_DAILY );
	}

	/** WP-Cron entry point. In "system" mode the real cron (WP-CLI) does the work. */
	public function cron_tick(): void {
		if ( 'system' === Settings::get( 'cron_mode' ) ) {
			return;
		}
		$this->run( 25 );
	}

	public function cron_daily(): void {
		$now = $this->services->clock()->now();
		$this->services->jobs()->enqueue( JobType::RotateLogs, null, array(), hash( 'sha256', 'rotate_logs' ), $now );
		$this->services->jobs()->enqueue( JobType::PruneStorage, null, array(), hash( 'sha256', 'prune_storage' ), $now );
	}

	/**
	 * @return array{scheduled: int, processed: int, failed: int}
	 */
	public function run( int $seconds, ?int $only_source = null ): array {
		update_option( self::LAST_TICK_OPTION, time(), false );
		$deadline  = microtime( true ) + max( 5, $seconds );
		$scheduled = $this->schedule_due( $only_source );
		$processed = 0;
		$failed    = 0;
		while ( microtime( true ) < $deadline ) {
			$now = $this->services->clock()->now();
			$job = $this->services->jobs()->claim( $now, $now->modify( '+' . self::LEASE_SECONDS . ' seconds' ) );
			if ( null === $job ) {
				break;
			}
			if ( $this->handle( $job ) ) {
				++$processed;
			} else {
				++$failed;
			}
		}
		return array(
			'scheduled' => $scheduled,
			'processed' => $processed,
			'failed'    => $failed,
		);
	}

	public function schedule_due( ?int $only_source = null ): int {
		$now   = $this->services->clock()->now();
		$count = 0;
		foreach ( $this->services->sync_state()->due( $now, 50 ) as $source_id ) {
			if ( null !== $only_source && $only_source !== $source_id ) {
				continue;
			}
			$source = $this->services->sources()->find( $source_id );
			if ( null === $source || ! $source->settings->syncEnabled ) {
				continue;
			}
			$this->services->jobs()->enqueue( JobType::SyncSource, $source_id, array( 'full' => false ), hash( 'sha256', 'sync_source|' . $source_id ), $now );
			++$count;
		}
		return $count;
	}

	private function handle( Job $job ): bool {
		$jobs = $this->services->jobs();
		try {
			switch ( $job->type ) {
				case JobType::SyncSource:
					if ( null !== $job->sourceId ) {
						// The sync service records failures and its own backoff; the job itself is done.
						$this->services->sync()->sync( $job->sourceId, (bool) ( $job->payload['full'] ?? false ) );
					}
					break;
				case JobType::SyncAttachment:
					if ( null !== $job->sourceId ) {
						$this->services->attachment_sync()->process( $job->sourceId, (string) ( $job->payload['ref_hash'] ?? '' ) );
					}
					break;
				case JobType::PruneStorage:
					( new Maintenance( $this->services ) )->prune_storage();
					break;
				case JobType::RotateLogs:
					( new Maintenance( $this->services ) )->rotate();
					break;
				case JobType::RebuildCache:
					$this->services->cache()->clear();
					break;
			}
			$jobs->complete( $job->id );
			return true;
		} catch ( CalendarException $e ) {
			$retry = $e->isRetryable() && $job->attempts < self::MAX_ATTEMPTS
				? $this->services->clock()->now()->modify( '+' . max( (int) $e->retryAfterSeconds, 60 * ( 2 ** min( 6, $job->attempts ) ) + random_int( 0, 30 ) ) . ' seconds' )
				: null;
			$jobs->fail( $job->id, $e->errorCode->value . ': ' . $e->getMessage(), $retry );
		} catch ( \Throwable $e ) {
			$this->services->logger()->log(
				'error',
				'job_crashed',
				'Background job failed unexpectedly.',
				array(
					'job_type'  => $job->type->value,
					'exception' => get_class( $e ),
				)
			);
			$jobs->fail( $job->id, 'UNEXPECTED: ' . get_class( $e ), null );
		}
		return false;
	}
}
