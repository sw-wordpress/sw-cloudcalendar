<?php
/**
 * Versioned database schema (SPECIFICATIONS.md §4).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

/**
 * Tables are created per site with `$wpdb->prefix` (multisite: every site has its own calendar data).
 */
final class Schema {

	public const VERSION_OPTION = 'sw_calendar_db_version';
	public const CURRENT        = 1;

	/**
	 * Short table names (without prefix).
	 *
	 * @return list<string>
	 */
	public static function tables(): array {
		return array(
			'connections',
			'secrets',
			'sources',
			'occurrences',
			'attachments',
			'views',
			'templates',
			'sync_state',
			'sync_runs',
			'jobs',
			'provider_items',
			'sync_staging',
			'logs',
			'audit',
		);
	}

	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, self::tables(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown table.' );
		}
		return $wpdb->prefix . 'swc_' . $name;
	}

	public static function needs_migration(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) < self::CURRENT;
	}

	/**
	 * Runs pending migrations; safe to call repeatedly. The version is only stored when every table exists afterwards
	 * (dbDelta() does not report failures).
	 */
	public static function migrate(): bool {
		$installed = (int) get_option( self::VERSION_OPTION, 0 );
		if ( $installed >= self::CURRENT ) {
			return true;
		}
		if ( $installed < 1 ) {
			self::migrate_to_1();
		}
		if ( array() !== self::missing_tables() ) {
			return false;
		}
		update_option( self::VERSION_OPTION, self::CURRENT, true );
		return true;
	}

	/**
	 * @return list<string> Full names of missing tables.
	 */
	public static function missing_tables(): array {
		global $wpdb;
		$missing  = array();
		$suppress = $wpdb->suppress_errors( true );
		foreach ( self::tables() as $name ) {
			$table = self::table( $name );
			// A probe query also sees temporary tables of the WordPress test suite.
			if ( false === $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed allowlist + prefix.
				$missing[] = $table;
			}
		}
		$wpdb->suppress_errors( $suppress );
		return $missing;
	}

	public static function drop_all(): void {
		global $wpdb;
		foreach ( self::tables() as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- explicit uninstall with data removal.
		}
		delete_option( self::VERSION_OPTION );
	}

	private static function migrate_to_1(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'swc_';

		$statements = array(
			"CREATE TABLE {$p}connections (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider varchar(24) NOT NULL,
  name varchar(190) NOT NULL,
  auth_mode varchar(24) NOT NULL,
  status varchar(24) NOT NULL,
  account_ref varchar(190) NULL,
  settings_json longtext NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY provider_status (provider,status)
) $charset;",
			"CREATE TABLE {$p}secrets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  connection_id bigint(20) unsigned NOT NULL,
  ciphertext longtext NOT NULL,
  key_version int(11) NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY connection_id (connection_id)
) $charset;",
			"CREATE TABLE {$p}sources (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  connection_id bigint(20) unsigned NOT NULL,
  external_id varchar(512) NOT NULL,
  external_id_hash char(64) NOT NULL,
  name varchar(190) NOT NULL,
  color char(7) NOT NULL DEFAULT '#3366cc',
  timezone varchar(64) NOT NULL DEFAULT 'UTC',
  visibility varchar(20) NOT NULL DEFAULT 'authenticated',
  roles_json text NULL,
  settings_json longtext NOT NULL,
  revision bigint(20) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'draft',
  last_success_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY connection_external (connection_id,external_id_hash),
  KEY status_visibility (status,visibility)
) $charset;",
			"CREATE TABLE {$p}occurrences (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  occurrence_key char(64) NOT NULL,
  provider_event_id varchar(512) NOT NULL,
  series_id varchar(512) NULL,
  recurrence_id varchar(190) NULL,
  title text NOT NULL,
  description_html longtext NOT NULL,
  description_text longtext NOT NULL,
  location text NOT NULL,
  external_url text NOT NULL,
  start_utc datetime NOT NULL,
  end_utc datetime NOT NULL,
  timezone varchar(64) NOT NULL,
  all_day tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL,
  payload_hash char(64) NOT NULL,
  seen_generation bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_occurrence (source_id,occurrence_key),
  KEY source_start (source_id,start_utc),
  KEY start_end (start_utc,end_utc),
  KEY source_generation (source_id,seen_generation)
) $charset;",
			"CREATE TABLE {$p}attachments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  occurrence_id bigint(20) unsigned NOT NULL,
  provider_ref_hash char(64) NOT NULL,
  ref_kind varchar(24) NOT NULL,
  provider_id varchar(2048) NOT NULL,
  provider_event_id varchar(512) NOT NULL,
  filename varchar(255) NOT NULL,
  mime varchar(190) NOT NULL,
  size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  version_token varchar(190) NULL,
  sha256 char(64) NULL,
  storage_key varchar(512) NULL,
  preview_key varchar(512) NULL,
  is_image tinyint(1) NOT NULL DEFAULT 0,
  publication_state varchar(24) NOT NULL,
  sync_state varchar(24) NOT NULL,
  last_checked_at datetime NULL,
  last_error text NULL,
  revoked_at datetime NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY occurrence_ref (occurrence_id,provider_ref_hash),
  KEY source_sync (source_id,sync_state),
  KEY source_publication (source_id,publication_state),
  KEY source_ref (source_id,provider_ref_hash),
  KEY storage_key (storage_key(190))
) $charset;",
			"CREATE TABLE {$p}views (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  config_json longtext NOT NULL,
  revision bigint(20) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY enabled (enabled)
) $charset;",
			"CREATE TABLE {$p}templates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  kind varchar(24) NOT NULL,
  editor_mode varchar(24) NOT NULL,
  body longtext NOT NULL,
  css longtext NOT NULL,
  schema_json longtext NULL,
  preset_key varchar(64) NULL,
  revision bigint(20) unsigned NOT NULL DEFAULT 1,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY kind (kind)
) $charset;",
			"CREATE TABLE {$p}sync_state (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  sync_mode varchar(24) NOT NULL DEFAULT 'full',
  cursor_ciphertext longtext NULL,
  window_start_utc datetime NULL,
  window_end_utc datetime NULL,
  generation bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(24) NOT NULL DEFAULT 'idle',
  failures int(11) NOT NULL DEFAULT 0,
  locked_until datetime NULL,
  next_attempt_at datetime NULL,
  last_success_at datetime NULL,
  last_error_code varchar(80) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_id (source_id),
  KEY next_attempt_at (next_attempt_at)
) $charset;",
			"CREATE TABLE {$p}sync_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  started_at datetime NOT NULL,
  finished_at datetime NULL,
  status varchar(24) NOT NULL,
  sync_mode varchar(24) NOT NULL DEFAULT 'full',
  created_count int(11) NOT NULL DEFAULT 0,
  updated_count int(11) NOT NULL DEFAULT 0,
  deleted_count int(11) NOT NULL DEFAULT 0,
  attachment_count int(11) NOT NULL DEFAULT 0,
  error_code varchar(80) NULL,
  message text NULL,
  metrics_json longtext NULL,
  PRIMARY KEY  (id),
  KEY source_started (source_id,started_at)
) $charset;",
			"CREATE TABLE {$p}jobs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NULL,
  job_type varchar(48) NOT NULL,
  payload_json longtext NOT NULL,
  status varchar(20) NOT NULL,
  attempts int(11) NOT NULL DEFAULT 0,
  run_after datetime NOT NULL,
  lock_until datetime NULL,
  idempotency_key char(64) NOT NULL,
  last_error text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY status_run_after (status,run_after),
  KEY source_id (source_id)
) $charset;",
			"CREATE TABLE {$p}provider_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  item_hash char(64) NOT NULL,
  payload longtext NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_item (source_id,item_hash)
) $charset;",
			"CREATE TABLE {$p}sync_staging (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  source_id bigint(20) unsigned NOT NULL,
  item_hash char(64) NOT NULL,
  op varchar(10) NOT NULL,
  payload longtext NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id)
) $charset;",
			"CREATE TABLE {$p}logs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  level varchar(10) NOT NULL,
  event_code varchar(60) NOT NULL,
  source_id bigint(20) unsigned NULL,
  message text NOT NULL,
  context longtext NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY source_created (source_id,created_at)
) $charset;",
			"CREATE TABLE {$p}audit (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(60) NOT NULL,
  object_type varchar(30) NOT NULL,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  context longtext NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY object (object_type,object_id)
) $charset;",
		);
		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}
	}
}
