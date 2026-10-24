<?php
/**
 * Calendar sources.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\SourceRepositoryInterface;
use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Domain\Source\SourceStatus;
use SW\Calendar\Domain\Source\Visibility;

final class SourceRepository implements SourceRepositoryInterface {

	public function __construct( private readonly int $max_window_days = SourceSettings::DEFAULT_MAX_WINDOW_DAYS ) {
	}

	public function find( int $id ): ?Source {
		$table = Schema::table( 'sources' );
		$row   = Db::wpdb()->get_row( Db::wpdb()->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->map( $row ) : null;
	}

	public function findMany( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$table  = Schema::table( 'sources' );
		$sql    = "SELECT * FROM {$table} WHERE id IN (" . Db::int_placeholders( $ids ) . ')';
		$rows   = Db::wpdb()->get_results( Db::wpdb()->prepare( $sql, ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$source                = $this->map( $row );
			$result[ $source->id ] = $source;
		}
		return $result;
	}

	public function all(): array {
		$table = Schema::table( 'sources' );
		$rows  = Db::wpdb()->get_results( "SELECT * FROM {$table} ORDER BY name ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( $this, 'map' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @param list<string> $roles
	 */
	public function create( int $connection_id, string $external_id, string $name, string $color, string $timezone, Visibility $visibility, array $roles, SourceSettings $settings ): int {
		$now = Db::now();
		Db::wpdb()->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'sources' ),
			array(
				'connection_id'    => $connection_id,
				'external_id'      => $external_id,
				'external_id_hash' => hash( 'sha256', $external_id ),
				'name'             => $name,
				'color'            => $color,
				'timezone'         => $timezone,
				'visibility'       => $visibility->value,
				'roles_json'       => Db::encode( $roles ),
				'settings_json'    => Db::encode( $settings->toArray() ),
				'revision'         => 1,
				'status'           => SourceStatus::Draft->value,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return (int) Db::wpdb()->insert_id;
	}

	/**
	 * Updates editable fields and increments the revision (immediately changes cache keys and ACL results).
	 *
	 * @param list<string> $roles
	 */
	public function update( int $id, string $name, string $color, string $timezone, Visibility $visibility, array $roles, SourceSettings $settings, SourceStatus $status ): void {
		$table = Schema::table( 'sources' );
		Db::wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"UPDATE {$table} SET name = %s, color = %s, timezone = %s, visibility = %s, roles_json = %s, settings_json = %s, status = %s, revision = revision + 1, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$name,
				$color,
				$timezone,
				$visibility->value,
				Db::encode( $roles ),
				Db::encode( $settings->toArray() ),
				$status->value,
				Db::now(),
				$id
			)
		);
	}

	public function bumpRevision( int $id ): void {
		$table = Schema::table( 'sources' );
		Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET revision = revision + 1 WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function markSuccess( int $id, \DateTimeImmutable $at ): void {
		Db::wpdb()->update( Schema::table( 'sources' ), array( 'last_success_at' => Db::format( $at ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function setStatus( int $id, SourceStatus $status ): void {
		$table = Schema::table( 'sources' );
		Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET status = %s, revision = revision + 1, updated_at = %s WHERE id = %d", $status->value, Db::now(), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function delete( int $id ): void {
		Db::wpdb()->delete( Schema::table( 'sources' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return list<int>
	 */
	public function ids_for_connection( int $connection_id ): array {
		$table = Schema::table( 'sources' );
		$ids   = Db::wpdb()->get_col( Db::wpdb()->prepare( "SELECT id FROM {$table} WHERE connection_id = %d", $connection_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function map( array $row ): Source {
		$roles = array_values( array_filter( array_map( 'strval', Db::json( $row['roles_json'] ) ) ) );
		return new Source(
			(int) $row['id'],
			(int) $row['connection_id'],
			(string) $row['external_id'],
			(string) $row['name'],
			(string) $row['color'],
			(string) $row['timezone'],
			Visibility::tryFrom( (string) $row['visibility'] ) ?? Visibility::Roles,
			$roles,
			SourceSettings::fromArray( Db::json( $row['settings_json'] ), $this->max_window_days ),
			(int) $row['revision'],
			// Unknown states are treated as disabled (fail closed).
			SourceStatus::tryFrom( (string) $row['status'] ) ?? SourceStatus::Disabled,
			Db::date( $row['last_success_at'] )
		);
	}
}
