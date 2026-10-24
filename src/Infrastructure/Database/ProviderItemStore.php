<?php
/**
 * Provider item mirror with per run staging.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\ProviderItemStoreInterface;
use SW\Calendar\Domain\Event\ProviderEvent;
use SW\Calendar\Support\LoggerInterface;

final class ProviderItemStore implements ProviderItemStoreInterface {

	private const BATCH = 500;

	public function __construct( private readonly ?LoggerInterface $logger = null ) {
	}

	public function stage( int $runId, int $sourceId, array $items, array $removedItemIds ): void {
		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = array( self::hash( $item->itemId ), 'upsert', Db::encode( $item->toArray() ) );
		}
		foreach ( $removedItemIds as $item_id ) {
			$rows[] = array( self::hash( $item_id ), 'remove', null );
		}
		$table = Schema::table( 'sync_staging' );
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$sql  = "INSERT INTO {$table} (run_id, source_id, item_hash, op, payload) VALUES " . implode( ',', array_fill( 0, count( $chunk ), '(%d, %d, %s, %s, %s)' ) );
			$args = array();
			foreach ( $chunk as $row ) {
				array_push( $args, $runId, $sourceId, $row[0], $row[1], (string) $row[2] );
			}
			Db::wpdb()->query( Db::wpdb()->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	public function discardStaging( int $runId ): void {
		Db::wpdb()->delete( Schema::table( 'sync_staging' ), array( 'run_id' => $runId ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function applyStaging( int $runId, int $sourceId, bool $fullSnapshot ): void {
		$wpdb    = Db::wpdb();
		$items   = Schema::table( 'provider_items' );
		$staging = Schema::table( 'sync_staging' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names from the schema allowlist.
		if ( $fullSnapshot ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$items} WHERE source_id = %d", $sourceId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		// Staging rows are applied in insertion order; the last operation per item wins.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$items} (source_id, item_hash, payload, updated_at)
				SELECT s.source_id, s.item_hash, s.payload, %s FROM {$staging} s
				WHERE s.run_id = %d AND s.op = 'upsert' AND s.id = (SELECT MAX(s2.id) FROM {$staging} s2 WHERE s2.run_id = s.run_id AND s2.item_hash = s.item_hash)
				ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Db::now(),
				$runId
			)
		);
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE i FROM {$items} i JOIN {$staging} s ON s.item_hash = i.item_hash AND s.source_id = i.source_id
				WHERE s.run_id = %d AND s.op = 'remove' AND i.source_id = %d
				AND s.id = (SELECT MAX(s2.id) FROM {$staging} s2 WHERE s2.run_id = s.run_id AND s2.item_hash = s.item_hash)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$runId,
				$sourceId
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->discardStaging( $runId );
	}

	public function loadItems( int $sourceId ): iterable {
		$table   = Schema::table( 'provider_items' );
		$last_id = 0;
		do {
			$count = 0;
			$rows  = Db::wpdb()->get_results( Db::wpdb()->prepare( "SELECT id, payload FROM {$table} WHERE source_id = %d AND id > %d ORDER BY id ASC LIMIT %d", $sourceId, $last_id, self::BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = is_array( $rows ) ? $rows : array();
			$count = count( $rows );
			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				try {
					yield ProviderEvent::fromArray( Db::json( $row['payload'] ) );
				} catch ( \Throwable $e ) {
					// A single unreadable item (e.g. after a format change) must not stop the source.
					$this->logger?->log( 'warning', 'item_unreadable', 'A stored provider item could not be decoded.', array( 'source_id' => $sourceId ) );
				}
			}
		} while ( self::BATCH === $count );
	}

	public function deleteSource( int $sourceId ): void {
		Db::wpdb()->delete( Schema::table( 'provider_items' ), array( 'source_id' => $sourceId ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Db::wpdb()->delete( Schema::table( 'sync_staging' ), array( 'source_id' => $sourceId ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Removes staging rows of crashed runs. */
	public function purge_stale_staging( int $older_than_run_id ): void {
		$table = Schema::table( 'sync_staging' );
		Db::wpdb()->query( Db::wpdb()->prepare( "DELETE FROM {$table} WHERE run_id < %d", $older_than_run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function hash( string $item_id ): string {
		return hash( 'sha256', $item_id );
	}
}
