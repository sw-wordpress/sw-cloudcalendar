<?php
/**
 * Sync run history (diagnostics). Stores counts and codes only.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\SyncRunStoreInterface;
use SW\Calendar\Application\Sync\SyncMetrics;

final class SyncRunStore implements SyncRunStoreInterface {

	public function start( int $sourceId, string $mode, \DateTimeImmutable $now ): int {
		Db::wpdb()->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'sync_runs' ),
			array(
				'source_id'  => $sourceId,
				'started_at' => Db::format( $now ),
				'status'     => 'running',
				'sync_mode'  => $mode,
			),
			array( '%d', '%s', '%s', '%s' )
		);
		return (int) Db::wpdb()->insert_id;
	}

	public function finish( int $runId, string $status, SyncMetrics $metrics, ?string $errorCode, ?string $message, \DateTimeImmutable $now ): void {
		Db::wpdb()->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'sync_runs' ),
			array(
				'finished_at'      => Db::format( $now ),
				'status'           => $status,
				'sync_mode'        => $metrics->mode,
				'created_count'    => $metrics->created,
				'updated_count'    => $metrics->updated,
				'deleted_count'    => $metrics->deleted,
				'attachment_count' => $metrics->attachmentsPlanned,
				'error_code'       => $errorCode,
				'message'          => $message,
				'metrics_json'     => Db::encode( $metrics->toArray() ),
			),
			array( 'id' => $runId ),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recent( ?int $source_id, int $limit, int $offset = 0 ): array {
		$table = Schema::table( 'sync_runs' );
		$rows  = null === $source_id
			? Db::wpdb()->get_results( Db::wpdb()->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: Db::wpdb()->get_results( Db::wpdb()->prepare( "SELECT * FROM {$table} WHERE source_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $source_id, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$list[] = array(
				'id'          => (int) $row['id'],
				'source_id'   => (int) $row['source_id'],
				'started_at'  => Db::iso( $row['started_at'] ),
				'finished_at' => Db::iso( $row['finished_at'] ),
				'status'      => (string) $row['status'],
				'mode'        => (string) $row['sync_mode'],
				'created'     => (int) $row['created_count'],
				'updated'     => (int) $row['updated_count'],
				'deleted'     => (int) $row['deleted_count'],
				'attachments' => (int) $row['attachment_count'],
				'error_code'  => $row['error_code'],
				'message'     => $row['message'],
				'metrics'     => Db::json( $row['metrics_json'] ),
			);
		}
		return $list;
	}

	public function count( ?int $source_id ): int {
		$table = Schema::table( 'sync_runs' );
		return (int) ( null === $source_id
			? Db::wpdb()->get_var( "SELECT COUNT(*) FROM {$table}" ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: Db::wpdb()->get_var( Db::wpdb()->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_id = %d", $source_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Marks runs of crashed processes as failed. */
	public function abandon_stale( \DateTimeImmutable $older_than ): int {
		$table = Schema::table( 'sync_runs' );
		return (int) Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET status = 'failed', error_code = 'ABORTED', finished_at = %s WHERE status = 'running' AND started_at < %s", Db::now(), Db::format( $older_than ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function purge( \DateTimeImmutable $older_than, int $keep_per_source = 50 ): int {
		$table = Schema::table( 'sync_runs' );
		// Keep the newest runs of every source even if they are old (diagnostics of rarely synced sources).
		return (int) Db::wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"DELETE r FROM {$table} r JOIN (SELECT source_id, MAX(id) - %d AS cutoff FROM {$table} GROUP BY source_id) k ON k.source_id = r.source_id WHERE r.started_at < %s AND r.id <= k.cutoff", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$keep_per_source,
				Db::format( $older_than )
			)
		);
	}
}
