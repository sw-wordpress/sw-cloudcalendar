<?php
/**
 * InnoDB transaction wrapper.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\TransactionInterface;

final class WpdbTransaction implements TransactionInterface {

	private int $depth = 0;

	/**
	 * @param bool $savepoints Use SAVEPOINTs instead of START TRANSACTION/COMMIT. The WordPress test suite wraps each
	 *                         test in a transaction that a COMMIT would end; production uses real transactions.
	 */
	public function __construct( private readonly bool $savepoints = false ) {
	}

	public function run( callable $callback ): mixed {
		$wpdb = Db::wpdb();
		// Nested calls join the outer transaction (MySQL has no real nested transactions).
		if ( $this->depth > 0 ) {
			return $callback();
		}
		$wpdb->query( $this->savepoints ? 'SAVEPOINT swc_tx' : 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- fixed statements.
		++$this->depth;
		try {
			$result = $callback();
			$wpdb->query( $this->savepoints ? 'RELEASE SAVEPOINT swc_tx' : 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- fixed statements.
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( $this->savepoints ? 'ROLLBACK TO SAVEPOINT swc_tx' : 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- fixed statements.
			throw $e;
		} finally {
			--$this->depth;
		}
	}
}
