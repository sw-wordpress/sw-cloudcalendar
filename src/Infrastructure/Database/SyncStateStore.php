<?php
/**
 * Per source sync state with atomic leases and encrypted cursors.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\SyncStateStoreInterface;
use SW\Calendar\Application\Sync\SyncStateRecord;
use SW\Calendar\Domain\Provider\SyncCursor;
use SW\Calendar\Domain\Provider\SyncWindow;
use SW\Calendar\Infrastructure\Crypto\SecretVault;

final class SyncStateStore implements SyncStateStoreInterface {

	public function __construct( private readonly SecretVault $vault ) {
	}

	public function acquireLock( int $sourceId, \DateTimeImmutable $now, \DateTimeImmutable $until ): bool {
		$this->ensure( $sourceId );
		$table = Schema::table( 'sync_state' );
		// One conditional UPDATE: only the process that changes the row owns the lease (no read-then-write race).
		$affected = Db::wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"UPDATE {$table} SET locked_until = %s, status = 'running' WHERE source_id = %d AND (locked_until IS NULL OR locked_until < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Db::format( $until ),
				$sourceId,
				Db::format( $now )
			)
		);
		return 1 === $affected;
	}

	public function releaseLock( int $sourceId ): void {
		$table = Schema::table( 'sync_state' );
		Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET locked_until = NULL, status = 'idle' WHERE source_id = %d", $sourceId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function load( int $sourceId ): SyncStateRecord {
		$table = Schema::table( 'sync_state' );
		$row   = Db::wpdb()->get_row( Db::wpdb()->prepare( "SELECT * FROM {$table} WHERE source_id = %d", $sourceId ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			return SyncStateRecord::initial();
		}
		$cursor = SyncCursor::empty();
		if ( is_string( $row['cursor_ciphertext'] ) && '' !== $row['cursor_ciphertext'] ) {
			try {
				$cursor = SyncCursor::fromJson( $this->vault->decrypt( $row['cursor_ciphertext'], 'cursor:' . $sourceId ) );
			} catch ( \Throwable $e ) {
				// Undecryptable cursor (key change): a full sync rebuilds it.
				$cursor = SyncCursor::empty();
			}
		}
		return new SyncStateRecord(
			$cursor,
			(int) $row['generation'],
			(int) $row['failures'],
			Db::date( $row['last_success_at'] ),
			null === $row['last_error_code'] ? null : (string) $row['last_error_code'],
			Db::date( $row['next_attempt_at'] ),
			(string) $row['status'],
			Db::date( $row['locked_until'] )
		);
	}

	public function saveSuccess( int $sourceId, SyncCursor $cursor, SyncWindow $window, int $generation, string $mode, \DateTimeImmutable $now, \DateTimeImmutable $nextAttempt ): void {
		$this->ensure( $sourceId );
		Db::wpdb()->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'sync_state' ),
			array(
				'sync_mode'         => $mode,
				'cursor_ciphertext' => $cursor->isEmpty() ? null : $this->vault->encrypt( $cursor->toJson(), 'cursor:' . $sourceId ),
				'window_start_utc'  => Db::format( $window->start ),
				'window_end_utc'    => Db::format( $window->end ),
				'generation'        => $generation,
				'failures'          => 0,
				'last_success_at'   => Db::format( $now ),
				'next_attempt_at'   => Db::format( $nextAttempt ),
				'last_error_code'   => null,
			),
			array( 'source_id' => $sourceId ),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function saveFailure( int $sourceId, string $errorCode, int $failures, \DateTimeImmutable $nextAttempt ): void {
		$this->ensure( $sourceId );
		Db::wpdb()->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'sync_state' ),
			array(
				'failures'        => $failures,
				'last_error_code' => mb_substr( $errorCode, 0, 80 ),
				'next_attempt_at' => Db::format( $nextAttempt ),
			),
			array( 'source_id' => $sourceId ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function resetCursor( int $sourceId ): void {
		$table = Schema::table( 'sync_state' );
		Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET cursor_ciphertext = NULL, next_attempt_at = NULL WHERE source_id = %d", $sourceId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Makes the source due immediately (manual sync, settings change).
	 */
	public function make_due( int $source_id ): void {
		$this->ensure( $source_id );
		$table = Schema::table( 'sync_state' );
		Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET next_attempt_at = NULL WHERE source_id = %d", $source_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function due( \DateTimeImmutable $now, int $limit ): array {
		$state   = Schema::table( 'sync_state' );
		$sources = Schema::table( 'sources' );
		// Sources without a state row are due as well (never synchronised).
		$ids = Db::wpdb()->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"SELECT s.id FROM {$sources} s LEFT JOIN {$state} st ON st.source_id = s.id
				WHERE s.status IN ('active','error') AND (st.id IS NULL OR st.next_attempt_at IS NULL OR st.next_attempt_at <= %s)
				AND (st.locked_until IS NULL OR st.locked_until < %s)
				ORDER BY st.next_attempt_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Db::format( $now ),
				Db::format( $now ),
				$limit
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	public function delete( int $source_id ): void {
		Db::wpdb()->delete( Schema::table( 'sync_state' ), array( 'source_id' => $source_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function ensure( int $source_id ): void {
		$table = Schema::table( 'sync_state' );
		Db::wpdb()->query( Db::wpdb()->prepare( "INSERT IGNORE INTO {$table} (source_id, status, generation, failures) VALUES (%d, 'idle', 0, 0)", $source_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
