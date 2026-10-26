<?php
/**
 * Structured operational log in a dedicated table (bounded by rotation).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Support\LoggerInterface;
use SW\Calendar\Support\Redactor;

final class DbLogger implements LoggerInterface {

	private const LEVELS = array(
		'debug'   => 0,
		'info'    => 1,
		'warning' => 2,
		'error'   => 3,
	);

	public function __construct( private readonly string $min_level = 'info' ) {
	}

	public function log( string $level, string $eventCode, string $message, array $context = array() ): void {
		if ( ( self::LEVELS[ $level ] ?? 3 ) < ( self::LEVELS[ $this->min_level ] ?? 1 ) ) {
			return;
		}
		$source_id = isset( $context['source_id'] ) ? (int) $context['source_id'] : null;
		$safe      = array();
		foreach ( $context as $key => $value ) {
			$safe[ (string) $key ] = is_string( $value ) ? Redactor::text( $value, 200 ) : $value;
		}
		$suppress = Db::wpdb()->suppress_errors( true );
		Db::wpdb()->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'logs' ),
			array(
				'created_at' => Db::now(),
				'level'      => mb_substr( $level, 0, 10 ),
				'event_code' => mb_substr( $eventCode, 0, 60 ),
				'source_id'  => $source_id,
				'message'    => Redactor::text( $message, 500 ),
				'context'    => Db::encode( $safe ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		Db::wpdb()->suppress_errors( $suppress );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $limit, int $offset = 0, ?string $level = null, ?int $source_id = null ): array {
		$table = Schema::table( 'logs' );
		$where = '1 = 1';
		$args  = array();
		if ( null !== $level && isset( self::LEVELS[ $level ] ) ) {
			$where .= ' AND level = %s';
			$args[] = $level;
		}
		if ( null !== $source_id ) {
			$where .= ' AND source_id = %d';
			$args[] = $source_id;
		}
		$args[] = $limit;
		$args[] = $offset;
		$rows   = Db::wpdb()->get_results( Db::wpdb()->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$list[] = array(
				'id'         => (int) $row['id'],
				'created_at' => Db::iso( $row['created_at'] ),
				'level'      => (string) $row['level'],
				'code'       => (string) $row['event_code'],
				'source_id'  => null === $row['source_id'] ? null : (int) $row['source_id'],
				'message'    => (string) $row['message'],
				'context'    => Db::json( $row['context'] ),
			);
		}
		return $list;
	}

	public function count( ?string $level = null, ?int $source_id = null ): int {
		$table = Schema::table( 'logs' );
		$where = '1 = 1';
		$args  = array();
		if ( null !== $level && isset( self::LEVELS[ $level ] ) ) {
			$where .= ' AND level = %s';
			$args[] = $level;
		}
		if ( null !== $source_id ) {
			$where .= ' AND source_id = %d';
			$args[] = $source_id;
		}
		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		return (int) ( array() === $args ? Db::wpdb()->get_var( $sql ) : Db::wpdb()->get_var( Db::wpdb()->prepare( $sql, ...$args ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL fragments and placeholders.
	}

	public function purge( \DateTimeImmutable $older_than, int $max_rows = 20000 ): int {
		$table   = Schema::table( 'logs' );
		$deleted = (int) Db::wpdb()->query( Db::wpdb()->prepare( "DELETE FROM {$table} WHERE created_at < %s", Db::format( $older_than ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max_id  = (int) Db::wpdb()->get_var( "SELECT MAX(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $max_id > $max_rows ) {
			$deleted += (int) Db::wpdb()->query( Db::wpdb()->prepare( "DELETE FROM {$table} WHERE id <= %d", $max_id - $max_rows ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $deleted;
	}
}
