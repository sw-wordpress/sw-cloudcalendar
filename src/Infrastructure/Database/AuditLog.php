<?php
/**
 * Append-only record of security relevant administrative decisions (publication confirmations, ACL changes,
 * connection removal). Never contains secrets.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

final class AuditLog {

	/**
	 * @param array<string, scalar|null> $context
	 */
	public function record( string $action, string $object_type, int $object_id, array $context = array() ): void {
		Db::wpdb()->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'audit' ),
			array(
				'created_at'  => Db::now(),
				'user_id'     => get_current_user_id(),
				'action'      => mb_substr( $action, 0, 60 ),
				'object_type' => mb_substr( $object_type, 0, 30 ),
				'object_id'   => $object_id,
				'context'     => Db::encode( $context ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $limit, int $offset = 0 ): array {
		$table = Schema::table( 'audit' );
		$rows  = Db::wpdb()->get_results( Db::wpdb()->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$list[] = array(
				'id'          => (int) $row['id'],
				'created_at'  => (string) $row['created_at'],
				'user_id'     => (int) $row['user_id'],
				'action'      => (string) $row['action'],
				'object_type' => (string) $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'context'     => Db::json( $row['context'] ),
			);
		}
		return $list;
	}
}
