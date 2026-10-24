<?php
/**
 * Connections (provider accounts, ICS configurations). Secrets live in the encrypted credential store.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\ConnectionRepositoryInterface;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;

final class ConnectionRepository implements ConnectionRepositoryInterface {

	public function find( int $id ): ?Connection {
		$table = Schema::table( 'connections' );
		$row   = Db::wpdb()->get_row( Db::wpdb()->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->map( $row ) : null;
	}

	/**
	 * @return list<Connection>
	 */
	public function all(): array {
		$table = Schema::table( 'connections' );
		$rows  = Db::wpdb()->get_results( "SELECT * FROM {$table} ORDER BY name ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( $this, 'map' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function create( Provider $provider, string $name, AuthMode $auth_mode, ConnectionStatus $status, array $settings ): int {
		$now = Db::now();
		Db::wpdb()->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'connections' ),
			array(
				'provider'      => $provider->value,
				'name'          => $name,
				'auth_mode'     => $auth_mode->value,
				'status'        => $status->value,
				'settings_json' => Db::encode( $settings ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) Db::wpdb()->insert_id;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function update( int $id, string $name, array $settings ): void {
		Db::wpdb()->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'connections' ),
			array(
				'name'          => $name,
				'settings_json' => Db::encode( $settings ),
				'updated_at'    => Db::now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function setStatus( int $id, ConnectionStatus $status ): void {
		Db::wpdb()->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'connections' ),
			array(
				'status'     => $status->value,
				'updated_at' => Db::now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function set_account_ref( int $id, ?string $account_ref ): void {
		Db::wpdb()->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'connections' ),
			array( 'account_ref' => null === $account_ref ? null : mb_substr( $account_ref, 0, 190 ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function delete( int $id ): void {
		Db::wpdb()->delete( Schema::table( 'connections' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function map( array $row ): Connection {
		return new Connection(
			(int) $row['id'],
			Provider::tryFrom( (string) $row['provider'] ) ?? Provider::Ics,
			(string) $row['name'],
			AuthMode::tryFrom( (string) $row['auth_mode'] ) ?? AuthMode::None,
			ConnectionStatus::tryFrom( (string) $row['status'] ) ?? ConnectionStatus::Failed,
			null === $row['account_ref'] ? null : (string) $row['account_ref'],
			Db::json( $row['settings_json'] ),
			Db::date( $row['created_at'] ),
			Db::date( $row['updated_at'] )
		);
	}
}
