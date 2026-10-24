<?php
/**
 * Encrypted per connection secrets.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\CredentialStoreInterface;
use SW\Calendar\Infrastructure\Crypto\SecretVault;

final class CredentialStore implements CredentialStoreInterface {

	public function __construct( private readonly SecretVault $vault ) {
	}

	public function get( int $connectionId ): array {
		$table = Schema::table( 'secrets' );
		$row   = Db::wpdb()->get_row( Db::wpdb()->prepare( "SELECT ciphertext FROM {$table} WHERE connection_id = %d", $connectionId ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			return array();
		}
		$ciphertext = (string) $row['ciphertext'];
		$data       = json_decode( $this->vault->decrypt( $ciphertext, $this->context( $connectionId ) ), true );
		$secrets    = array();
		foreach ( is_array( $data ) ? $data : array() as $key => $value ) {
			$secrets[ (string) $key ] = (string) $value;
		}
		if ( $this->vault->needsRotation( $ciphertext ) ) {
			$this->write( $connectionId, $secrets );
		}
		return $secrets;
	}

	public function update( int $connectionId, array $values ): void {
		$secrets = $this->get( $connectionId );
		foreach ( $values as $key => $value ) {
			if ( null === $value ) {
				unset( $secrets[ $key ] );
				continue;
			}
			$secrets[ $key ] = $value;
		}
		$this->write( $connectionId, $secrets );
	}

	public function delete( int $connectionId ): void {
		Db::wpdb()->delete( Schema::table( 'secrets' ), array( 'connection_id' => $connectionId ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Which secret keys are set (never the values).
	 *
	 * @return list<string>
	 */
	public function keys( int $connection_id ): array {
		try {
			return array_keys( array_filter( $this->get( $connection_id ), static fn ( string $value ): bool => '' !== $value ) );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * @param array<string, string> $secrets
	 */
	private function write( int $connection_id, array $secrets ): void {
		$table = Schema::table( 'secrets' );
		Db::wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"INSERT INTO {$table} (connection_id, ciphertext, key_version, updated_at) VALUES (%d, %s, %d, %s)
				ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext), key_version = VALUES(key_version), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$connection_id,
				$this->vault->encrypt( Db::encode( $secrets ), $this->context( $connection_id ) ),
				$this->vault->keyVersion(),
				Db::now()
			)
		);
	}

	private function context( int $connection_id ): string {
		return 'connection:' . $connection_id;
	}
}
