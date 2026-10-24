<?php
/**
 * Occurrences: generation based snapshot swap and the overlap query.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\OccurrenceQueryInterface;
use SW\Calendar\Application\Port\OccurrenceStoreInterface;
use SW\Calendar\Application\Render\QueryRange;
use SW\Calendar\Application\Sync\SnapshotDiff;
use SW\Calendar\Domain\Event\Occurrence;
use SW\Calendar\Domain\Event\OccurrenceStatus;

final class OccurrenceRepository implements OccurrenceStoreInterface, OccurrenceQueryInterface {

	private const CHUNK = 200;

	private const COLUMNS = 'id, source_id, occurrence_key, provider_event_id, series_id, recurrence_id, title, description_html, description_text, location, external_url, start_utc, end_utc, timezone, all_day, status';

	public function replaceSnapshot( int $sourceId, array $occurrences, int $generation ): SnapshotDiff {
		$wpdb     = Db::wpdb();
		$table    = Schema::table( 'occurrences' );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT id, occurrence_key, payload_hash FROM {$table} WHERE source_id = %d", $sourceId ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$existing[ (string) $row['occurrence_key'] ] = array(
				'id'   => (int) $row['id'],
				'hash' => (string) $row['payload_hash'],
			);
		}

		$now       = Db::now();
		$inserts   = array();
		$unchanged = array();
		$created   = 0;
		$updated   = 0;
		foreach ( $occurrences as $occurrence ) {
			$hash = $occurrence->payloadHash();
			$row  = $existing[ $occurrence->occurrenceKey ] ?? null;
			if ( null === $row ) {
				$inserts[] = $occurrence;
				++$created;
				continue;
			}
			if ( $row['hash'] !== $hash ) {
				$wpdb->update( $table, $this->values( $occurrence, $generation, $now ), array( 'id' => $row['id'] ), $this->formats(), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$updated;
				continue;
			}
			$unchanged[] = $row['id'];
		}
		foreach ( array_chunk( $unchanged, 500 ) as $chunk ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET seen_generation = %d WHERE id IN (" . Db::int_placeholders( $chunk ) . ')', $generation, ...$chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list built for the chunk.
		}
		foreach ( array_chunk( $inserts, self::CHUNK ) as $chunk ) {
			$this->insert_many( $chunk, $generation, $now );
		}

		$deleted_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_id = %d AND seen_generation < %d", $sourceId, $generation ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( array() !== $deleted_ids ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_id = %d AND seen_generation < %d", $sourceId, $generation ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$ids  = array();
		$keys = $wpdb->get_results( $wpdb->prepare( "SELECT id, occurrence_key FROM {$table} WHERE source_id = %d", $sourceId ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( is_array( $keys ) ? $keys : array() as $row ) {
			$ids[ (string) $row['occurrence_key'] ] = (int) $row['id'];
		}
		return new SnapshotDiff( $created, $updated, count( $deleted_ids ), $ids, $deleted_ids );
	}

	public function find(
		array $sourceIds,
		QueryRange $range,
		?string $search,
		?string $location,
		array $excludeIds,
		bool $descending,
		int $limit,
		int $offset,
		int $sortOffsetSeconds
	): array {
		$wpdb = Db::wpdb();
		$ids  = array_values( array_filter( array_map( 'intval', $sourceIds ), static fn ( int $id ): bool => $id > 0 ) );
		if ( array() === $ids ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}
		$table    = Schema::table( 'occurrences' );
		$statuses = OccurrenceStatus::visibleValues();
		$where    = 'source_id IN (' . Db::int_placeholders( $ids ) . ') AND status IN (' . Db::string_placeholders( $statuses ) . ')'
			. ' AND ((all_day = 0 AND start_utc < %s AND end_utc > %s) OR (all_day = 1 AND start_utc < %s AND end_utc > %s))';
		$args     = array_merge(
			$ids,
			$statuses,
			array(
				Db::format( $range->endUtc ),
				Db::format( $range->startUtc ),
				Db::format( $range->floatingEnd ),
				Db::format( $range->floatingStart ),
			)
		);
		if ( null !== $search && '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (title LIKE %s OR description_text LIKE %s OR location LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		if ( null !== $location && '' !== $location ) {
			$where .= ' AND location LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $location ) . '%';
		}
		$exclude = array_values( array_filter( array_map( 'intval', $excludeIds ), static fn ( int $id ): bool => $id > 0 ) );
		if ( array() !== $exclude ) {
			$where .= ' AND id NOT IN (' . Db::int_placeholders( $exclude ) . ')';
			$args   = array_merge( $args, $exclude );
		}
		$direction = $descending ? 'DESC' : 'ASC';
		// All-day rows hold floating dates; shift them by the view offset so they sort before timed events of that day.
		$order = "CASE WHEN all_day = 1 THEN DATE_SUB(start_utc, INTERVAL %d SECOND) ELSE start_utc END {$direction}, source_id ASC, id ASC";

		// $where/$order contain only fixed SQL and placeholders built above; all values are passed to prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT ' . self::COLUMNS . " FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d",
				...array_merge( $args, array( $sortOffsetSeconds, max( 1, $limit ), max( 0, $offset ) ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders
		return array(
			'items' => array_map( array( $this, 'map' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	public function findById( int $id ): ?Occurrence {
		$table = Schema::table( 'occurrences' );
		$row   = Db::wpdb()->get_row( Db::wpdb()->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->map( $row ) : null;
	}

	public function deleteSource( int $sourceId ): void {
		Db::wpdb()->delete( Schema::table( 'occurrences' ), array( 'source_id' => $sourceId ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function count_for_source( int $source_id ): int {
		$table = Schema::table( 'occurrences' );
		return (int) Db::wpdb()->get_var( Db::wpdb()->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_id = %d", $source_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param list<Occurrence> $occurrences
	 */
	private function insert_many( array $occurrences, int $generation, string $now ): void {
		if ( array() === $occurrences ) {
			return;
		}
		$table        = Schema::table( 'occurrences' );
		$columns      = array_keys( $this->values( $occurrences[0], $generation, $now ) );
		$placeholders = '(' . implode( ',', $this->formats() ) . ')';
		$sql          = "INSERT INTO {$table} (" . implode( ',', $columns ) . ') VALUES ' . implode( ',', array_fill( 0, count( $occurrences ), $placeholders ) );
		$args         = array();
		foreach ( $occurrences as $occurrence ) {
			$args = array_merge( $args, array_values( $this->values( $occurrence, $generation, $now ) ) );
		}
		Db::wpdb()->query( Db::wpdb()->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @return array<string, mixed>
	 */
	private function values( Occurrence $o, int $generation, string $now ): array {
		return array(
			'source_id'         => $o->sourceId,
			'occurrence_key'    => $o->occurrenceKey,
			'provider_event_id' => mb_substr( $o->providerEventId, 0, 512 ),
			'series_id'         => null === $o->seriesId ? null : mb_substr( $o->seriesId, 0, 512 ),
			'recurrence_id'     => $o->recurrenceId,
			'title'             => $o->title,
			'description_html'  => $o->descriptionHtml,
			'description_text'  => $o->descriptionText,
			'location'          => $o->location,
			'external_url'      => $o->externalUrl,
			'start_utc'         => Db::format( $o->startUtc ),
			'end_utc'           => Db::format( $o->endUtc ),
			'timezone'          => $o->timezone,
			'all_day'           => $o->allDay ? 1 : 0,
			'status'            => $o->status->value,
			'payload_hash'      => $o->payloadHash(),
			'seen_generation'   => $generation,
			'updated_at'        => $now,
		);
	}

	/**
	 * @return list<string>
	 */
	private function formats(): array {
		return array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function map( array $row ): Occurrence {
		$utc = new \DateTimeZone( 'UTC' );
		return new Occurrence(
			(int) $row['source_id'],
			(string) $row['occurrence_key'],
			(string) $row['provider_event_id'],
			// wpdb::prepare() turns NULL into '' in multi-row inserts; both mean "not set".
			'' === (string) $row['series_id'] ? null : (string) $row['series_id'],
			'' === (string) $row['recurrence_id'] ? null : (string) $row['recurrence_id'],
			(string) $row['title'],
			(string) $row['description_html'],
			(string) $row['description_text'],
			(string) $row['location'],
			(string) $row['external_url'],
			new \DateTimeImmutable( (string) $row['start_utc'], $utc ),
			new \DateTimeImmutable( (string) $row['end_utc'], $utc ),
			(string) $row['timezone'],
			(bool) $row['all_day'],
			OccurrenceStatus::tryFrom( (string) $row['status'] ) ?? OccurrenceStatus::Confirmed,
			array(),
			(int) $row['id']
		);
	}
}
