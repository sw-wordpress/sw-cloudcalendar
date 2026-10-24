<?php
/**
 * Database job queue with atomic claims and idempotency keys.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\JobQueueInterface;
use SW\Calendar\Domain\Job\Job;
use SW\Calendar\Domain\Job\JobStatus;
use SW\Calendar\Domain\Job\JobType;

final class JobQueue implements JobQueueInterface {

	public function enqueue( JobType $type, ?int $sourceId, array $payload, string $idempotencyKey, \DateTimeImmutable $runAfter ): void {
		$table  = Schema::table( 'jobs' );
		$now    = Db::now();
		$source = null === $sourceId ? 'NULL' : (string) (int) $sourceId;
		// Queued/running jobs with the same key stay untouched; finished ones are re-armed (e.g. a retry by an admin).
		Db::wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"INSERT INTO {$table} (source_id, job_type, payload_json, status, attempts, run_after, idempotency_key, created_at, updated_at)
				VALUES ({$source}, %s, %s, %s, 0, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					run_after = IF(status IN ('done','failed'), VALUES(run_after), run_after),
					attempts = IF(status IN ('done','failed'), 0, attempts),
					payload_json = IF(status IN ('done','failed'), VALUES(payload_json), payload_json),
					updated_at = VALUES(updated_at),
					status = IF(status IN ('done','failed'), 'queued', status)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type->value,
				Db::encode( $payload ),
				JobStatus::Queued->value,
				Db::format( $runAfter ),
				$idempotencyKey,
				$now,
				$now
			)
		);
	}

	public function claim( \DateTimeImmutable $now, \DateTimeImmutable $leaseUntil ): ?Job {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'jobs' );
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE (status = 'queued' AND run_after <= %s) OR (status = 'running' AND lock_until < %s) ORDER BY run_after ASC, id ASC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Db::format( $now ),
				Db::format( $now )
			)
		);
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name from the schema allowlist.
			$claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'running', lock_until = %s, attempts = attempts + 1, updated_at = %s
					WHERE id = %d AND ((status = 'queued' AND run_after <= %s) OR (status = 'running' AND lock_until < %s))",
					Db::format( $leaseUntil ),
					Db::now(),
					(int) $id,
					Db::format( $now ),
					Db::format( $now )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 1 === $claimed ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( is_array( $row ) ) {
					$job = $this->map( $row );
					if ( null !== $job ) {
						return $job;
					}
					$this->fail( (int) $id, 'Unknown job type.', null );
				}
			}
		}
		return null;
	}

	public function complete( int $jobId ): void {
		Db::wpdb()->update(
			Schema::table( 'jobs' ),
			array(
				'status'     => 'done',
				'lock_until' => null,
				'last_error' => null,
				'updated_at' => Db::now(),
			),
			array( 'id' => $jobId ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function fail( int $jobId, string $error, ?\DateTimeImmutable $retryAt ): void {
		$data = array(
			'status'     => null === $retryAt ? 'failed' : 'queued',
			'lock_until' => null,
			'last_error' => mb_substr( $error, 0, 500 ),
			'updated_at' => Db::now(),
		);
		if ( null !== $retryAt ) {
			$data['run_after'] = Db::format( $retryAt );
		}
		Db::wpdb()->update( Schema::table( 'jobs' ), $data, array( 'id' => $jobId ), null, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function deleteSource( int $sourceId ): void {
		Db::wpdb()->delete( Schema::table( 'jobs' ), array( 'source_id' => $sourceId ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array<string, int> Status => count.
	 */
	public function counts(): array {
		$table  = Schema::table( 'jobs' );
		$rows   = Db::wpdb()->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	public function has_pending( JobType $type, int $source_id ): bool {
		$table = Schema::table( 'jobs' );
		return (int) Db::wpdb()->get_var( Db::wpdb()->prepare( "SELECT COUNT(*) FROM {$table} WHERE job_type = %s AND source_id = %d AND status IN ('queued','running')", $type->value, $source_id ) ) > 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function purge_finished( \DateTimeImmutable $older_than ): int {
		$table = Schema::table( 'jobs' );
		return (int) Db::wpdb()->query( Db::wpdb()->prepare( "DELETE FROM {$table} WHERE status IN ('done','failed') AND updated_at < %s", Db::format( $older_than ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function map( array $row ): ?Job {
		$type = JobType::tryFrom( (string) $row['job_type'] );
		if ( null === $type ) {
			return null;
		}
		$payload = array();
		foreach ( Db::json( $row['payload_json'] ) as $key => $value ) {
			$payload[ (string) $key ] = is_scalar( $value ) ? $value : null;
		}
		return new Job(
			(int) $row['id'],
			null === $row['source_id'] || '' === (string) $row['source_id'] ? null : (int) $row['source_id'],
			$type,
			$payload,
			JobStatus::tryFrom( (string) $row['status'] ) ?? JobStatus::Queued,
			(int) $row['attempts'],
			Db::date( $row['run_after'] ) ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ),
			(string) $row['idempotency_key'],
			null === $row['last_error'] ? null : (string) $row['last_error']
		);
	}
}
