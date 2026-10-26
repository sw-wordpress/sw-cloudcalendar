<?php
/**
 * Calendar views.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Port\ViewRepositoryInterface;
use SW\Calendar\Domain\View\CalendarView;
use SW\Calendar\Domain\View\DisplayMode;

final class ViewRepository implements ViewRepositoryInterface {

	public function find( int $id ): ?CalendarView {
		$table = Schema::table( 'views' );
		$row   = Db::wpdb()->get_row( Db::wpdb()->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::map( $row ) : null;
	}

	/**
	 * @return list<CalendarView>
	 */
	public function all(): array {
		$table = Schema::table( 'views' );
		$rows  = Db::wpdb()->get_results( "SELECT * FROM {$table} ORDER BY name ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'map' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @param array<string, mixed> $config Validated configuration (ViewService).
	 */
	public function save( ?int $id, string $name, bool $enabled, array $config ): int {
		$table = Schema::table( 'views' );
		$now   = Db::now();
		if ( null === $id ) {
			Db::wpdb()->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'name'        => $name,
					'enabled'     => $enabled ? 1 : 0,
					'config_json' => Db::encode( $config ),
					'revision'    => 1,
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%s', '%d', '%s', '%d', '%s', '%s' )
			);
			return (int) Db::wpdb()->insert_id;
		}
		Db::wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"UPDATE {$table} SET name = %s, enabled = %d, config_json = %s, revision = revision + 1, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$name,
				$enabled ? 1 : 0,
				Db::encode( $config ),
				$now,
				$id
			)
		);
		return $id;
	}

	public function bump_all(): void {
		$table = Schema::table( 'views' );
		Db::wpdb()->query( "UPDATE {$table} SET revision = revision + 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function delete( int $id ): void {
		Db::wpdb()->delete( Schema::table( 'views' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Views referencing a template (to protect it from deletion).
	 *
	 * @return list<int>
	 */
	public function ids_using_template( int $template_id ): array {
		$ids = array();
		foreach ( $this->all() as $view ) {
			if ( in_array( $template_id, array_merge( array( $view->containerTemplateId, $view->eventTemplateId, $view->detailTemplateId ), $view->allowedTemplateIds ), true ) ) {
				$ids[] = $view->id;
			}
		}
		return $ids;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function map( array $row ): CalendarView {
		$config  = Db::json( $row['config_json'] );
		$int_ids = static fn ( mixed $list ): array => array_values( array_unique( array_filter( array_map( 'intval', is_array( $list ) ? $list : array() ), static fn ( int $id ): bool => $id > 0 ) ) );
		$opt_id  = static fn ( mixed $value ): ?int => is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
		$modes   = array();
		foreach ( (array) ( $config['allowed_modes'] ?? array() ) as $mode ) {
			$parsed = DisplayMode::tryFrom( (string) $mode );
			if ( null !== $parsed ) {
				$modes[] = $parsed;
			}
		}
		$colors = array();
		foreach ( (array) ( $config['color_rules'] ?? array() ) as $source_id => $color ) {
			if ( is_string( $color ) && 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
				$colors[ (int) $source_id ] = $color;
			}
		}
		return new CalendarView(
			(int) $row['id'],
			(string) $row['name'],
			(bool) $row['enabled'],
			$int_ids( $config['source_ids'] ?? array() ),
			is_array( $config['default_filters'] ?? null ) ? $config['default_filters'] : array(),
			DisplayMode::tryFrom( (string) ( $config['display_mode'] ?? 'list' ) ) ?? DisplayMode::List,
			$modes,
			$opt_id( $config['container_template_id'] ?? null ),
			$opt_id( $config['event_template_id'] ?? null ),
			$opt_id( $config['detail_template_id'] ?? null ),
			$int_ids( $config['allowed_template_ids'] ?? array() ),
			(string) ( $config['timezone'] ?? '' ),
			(string) ( $config['language'] ?? '' ),
			$colors,
			(int) $row['revision'],
			(bool) ( $config['html_cache'] ?? true ),
			(string) ( $config['date_format'] ?? '' ),
			(string) ( $config['time_format'] ?? '' ),
			(bool) ( $config['show_navigation'] ?? true )
		);
	}
}
