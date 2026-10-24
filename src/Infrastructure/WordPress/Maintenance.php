<?php
/**
 * Periodic maintenance: log rotation, storage garbage collection, crash recovery.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

use SW\Calendar\Services;

final class Maintenance {

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @return array<string, int>
	 */
	public function rotate(): array {
		$now = $this->services->clock()->now();
		update_option( QueueRunner::LAST_MAINTENANCE_OPTION, time(), false );
		return array(
			'logs'  => $this->services->logger()->purge( $now->modify( '-' . (int) Settings::get( 'log_retention_days' ) . ' days' ) ),
			'runs'  => $this->services->sync_runs()->purge( $now->modify( '-' . (int) Settings::get( 'run_retention_days' ) . ' days' ) ),
			'jobs'  => $this->services->jobs()->purge_finished( $now->modify( '-7 days' ) ),
			'stale' => $this->services->sync_runs()->abandon_stale( $now->modify( '-1 hour' ) ),
		);
	}

	/**
	 * Deletes files that no row references any more after the grace period (revoked/skipped files are already
	 * undeliverable; the grace period only protects against races with running downloads).
	 *
	 * @return array{deleted: int, keys: list<string>, temporary: int}
	 */
	public function prune_storage( bool $dry_run = false, int $limit = 500 ): array {
		$hours   = (int) Settings::get( 'revoked_file_grace_hours' );
		$before  = $this->services->clock()->now()->modify( '-' . $hours . ' hours' );
		$keys    = $this->services->attachments()->unreferencedStorageKeys( $before, $limit );
		$deleted = 0;
		if ( ! $dry_run ) {
			$storage = $this->services->storage();
			foreach ( $keys as $key ) {
				try {
					foreach ( $this->services->attachments()->preview_keys_for( $key ) as $preview ) {
						$storage->delete( $preview );
					}
					$storage->delete( $key );
				} catch ( \Throwable $e ) {
					// Invalid keys cannot point to files of ours; forget them anyway.
					unset( $e );
				}
				$this->services->attachments()->forgetStorageKey( $key );
				++$deleted;
			}
		}
		$temporary = $dry_run ? 0 : $this->services->storage()->purgeTemporary();
		return array(
			'deleted'   => $deleted,
			'keys'      => $dry_run ? $keys : array(),
			'temporary' => $temporary,
		);
	}
}
