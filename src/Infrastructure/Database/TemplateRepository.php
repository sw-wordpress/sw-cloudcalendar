<?php
/**
 * Calendar templates.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\TemplateRepositoryInterface;
use SW\Calendar\Domain\Template\EditorMode;
use SW\Calendar\Domain\Template\Template;
use SW\Calendar\Domain\Template\TemplateKind;

final class TemplateRepository implements TemplateRepositoryInterface {

	/** @var array<int, Template|null> Request memo, cleared on writes. */
	private array $memo = array();

	public function find( int $id ): ?Template {
		if ( array_key_exists( $id, $this->memo ) ) {
			return $this->memo[ $id ];
		}
		$table             = Schema::table( 'templates' );
		$row               = Db::wpdb()->get_row( Db::wpdb()->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->memo[ $id ] = is_array( $row ) ? $this->map( $row ) : null;
		return $this->memo[ $id ];
	}

	/**
	 * @return list<Template>
	 */
	public function all( ?TemplateKind $kind = null ): array {
		$table = Schema::table( 'templates' );
		$rows  = null === $kind
			? Db::wpdb()->get_results( "SELECT * FROM {$table} ORDER BY kind ASC, name ASC, id ASC", ARRAY_A ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: Db::wpdb()->get_results( Db::wpdb()->prepare( "SELECT * FROM {$table} WHERE kind = %s ORDER BY name ASC, id ASC", $kind->value ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( $this, 'map' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @param array<string, mixed>|null $schema
	 */
	public function save( ?int $id, string $name, TemplateKind $kind, EditorMode $mode, string $body, string $css, ?array $schema, ?string $preset_key = null ): int {
		$table      = Schema::table( 'templates' );
		$this->memo = array();
		if ( null === $id ) {
			Db::wpdb()->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'name'        => $name,
					'kind'        => $kind->value,
					'editor_mode' => $mode->value,
					'body'        => $body,
					'css'         => $css,
					'schema_json' => null === $schema ? null : Db::encode( $schema ),
					'preset_key'  => $preset_key,
					'revision'    => 1,
					'updated_at'  => Db::now(),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			return (int) Db::wpdb()->insert_id;
		}
		Db::wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"UPDATE {$table} SET name = %s, editor_mode = %s, body = %s, css = %s, schema_json = %s, revision = revision + 1, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$name,
				$mode->value,
				$body,
				$css,
				null === $schema ? null : Db::encode( $schema ),
				Db::now(),
				$id
			)
		);
		return $id;
	}

	public function delete( int $id ): void {
		$this->memo = array();
		Db::wpdb()->delete( Schema::table( 'templates' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function count(): int {
		$table = Schema::table( 'templates' );
		return (int) Db::wpdb()->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function map( array $row ): Template {
		$schema = null === $row['schema_json'] ? null : Db::json( $row['schema_json'] );
		return new Template(
			(int) $row['id'],
			(string) $row['name'],
			TemplateKind::tryFrom( (string) $row['kind'] ) ?? TemplateKind::Event,
			EditorMode::tryFrom( (string) $row['editor_mode'] ) ?? EditorMode::Html,
			(string) $row['body'],
			(string) $row['css'],
			$schema,
			(int) $row['revision'],
			Db::date( $row['updated_at'] ),
			null === $row['preset_key'] ? null : (string) $row['preset_key']
		);
	}
}
