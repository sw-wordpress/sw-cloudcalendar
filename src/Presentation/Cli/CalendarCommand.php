<?php
/**
 * WP-CLI: `wp sw-cloudcalendar …` (SPECIFICATIONS.md §15).
 *
 * Exit codes: 0 success, 1 error, 2 partial failure (some sources/files failed). No secrets are printed.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Cli;

use SW\Calendar\Application\Sync\SyncReport;
use SW\Calendar\Infrastructure\WordPress\Maintenance;
use SW\Calendar\Infrastructure\WordPress\QueueRunner;
use SW\Calendar\Presentation\Admin\ConnectionManager;
use SW\Calendar\Presentation\Admin\Diagnostics;
use SW\Calendar\Services;

final class CalendarCommand {

	private function services(): Services {
		return Services::instance();
	}

	/**
	 * Lists calendar sources.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand sources
	 */
	public function sources( array $args, array $assoc_args ): void {
		if ( 'list' !== ( $args[0] ?? 'list' ) ) {
			\WP_CLI::error( 'Unknown action. Use: wp sw-cloudcalendar sources list' );
		}
		$rows = array();
		foreach ( $this->services()->sources()->all() as $source ) {
			$state  = $this->services()->sync_state()->load( $source->id );
			$rows[] = array(
				'id'              => $source->id,
				'name'            => $source->name,
				'connection'      => $source->connectionId,
				'status'          => $source->status->value,
				'visibility'      => $source->visibility->value,
				'last_success_at' => $source->lastSuccessAt?->format( 'Y-m-d H:i:s' ) ?? '-',
				'last_error'      => $state->lastErrorCode ?? '-',
				'occurrences'     => $this->services()->occurrences()->count_for_source( $source->id ),
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'name', 'connection', 'status', 'visibility', 'last_success_at', 'last_error', 'occurrences' ) );
	}

	/**
	 * Tests a connection.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : test
	 *
	 * --id=<id>
	 * : Connection id.
	 */
	public function connections( array $args, array $assoc_args ): void {
		if ( 'test' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Unknown action. Use: wp sw-cloudcalendar connections test --id=<id>' );
		}
		try {
			$result = ( new ConnectionManager( $this->services() ) )->test( (int) ( $assoc_args['id'] ?? 0 ) );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		if ( ! $result['ok'] ) {
			\WP_CLI::error( sprintf( '%s (%s)', $result['message'], (string) $result['error_code'] ) );
		}
		\WP_CLI::success( $result['message'] );
	}

	/**
	 * Synchronises sources now (respects the per source lock).
	 *
	 * ## OPTIONS
	 *
	 * [--source=<id>]
	 * : One source.
	 *
	 * [--all]
	 * : All active sources.
	 *
	 * [--due]
	 * : Only sources whose next run is due.
	 *
	 * [--full]
	 * : Ignore stored cursors and rebuild completely.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$services = $this->services();
		if ( isset( $assoc_args['source'] ) ) {
			$ids = array( (int) $assoc_args['source'] );
		} elseif ( isset( $assoc_args['all'] ) ) {
			$ids = array_map( static fn ( $s ): int => $s->id, $services->sources()->all() );
		} elseif ( isset( $assoc_args['due'] ) ) {
			$ids = $services->sync_state()->due( $services->clock()->now(), 100 );
		} else {
			\WP_CLI::error( 'Use --source=<id>, --all or --due.' );
		}
		$failed = 0;
		foreach ( $ids as $id ) {
			$report = $services->sync()->sync( $id, isset( $assoc_args['full'] ) );
			$line   = sprintf( 'Source %d: %s (%s, +%d ~%d -%d)', $id, $report->status, $report->metrics->mode, $report->metrics->created, $report->metrics->updated, $report->metrics->deleted );
			if ( SyncReport::FAILED === $report->status ) {
				++$failed;
				\WP_CLI::warning( $line . ' ' . (string) $report->errorCode . ': ' . (string) $report->message );
			} else {
				\WP_CLI::log( $line );
			}
		}
		if ( $failed > 0 ) {
			\WP_CLI::halt( count( $ids ) === $failed ? 1 : 2 );
		}
		\WP_CLI::success( sprintf( '%d source(s) processed.', count( $ids ) ) );
	}

	/**
	 * Attachment synchronisation and storage cleanup.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : sync or prune
	 *
	 * [--source=<id>]
	 * : Source for "sync".
	 *
	 * [--dry-run]
	 * : "prune" only lists the files that would be deleted.
	 */
	public function attachments( array $args, array $assoc_args ): void {
		$services = $this->services();
		$action   = $args[0] ?? '';
		if ( 'prune' === $action ) {
			$dry    = isset( $assoc_args['dry-run'] );
			$result = ( new Maintenance( $services ) )->prune_storage( $dry );
			if ( $dry ) {
				\WP_CLI::log( sprintf( '%d file(s) would be deleted.', count( $result['keys'] ) ) );
				return;
			}
			\WP_CLI::success( sprintf( '%d file(s) deleted, %d temporary file(s) removed.', $result['deleted'], $result['temporary'] ) );
			return;
		}
		if ( 'sync' !== $action || ! isset( $assoc_args['source'] ) ) {
			\WP_CLI::error( 'Use: wp sw-cloudcalendar attachments sync --source=<id> | attachments prune [--dry-run]' );
		}
		$source = (int) $assoc_args['source'];
		$counts = array();
		$failed = 0;
		foreach ( $services->attachments()->pending_refs( $source ) as $ref ) {
			try {
				$outcome = $services->attachment_sync()->process( $source, $ref );
			} catch ( \Throwable $e ) {
				$outcome = 'failed';
			}
			$counts[ $outcome ] = ( $counts[ $outcome ] ?? 0 ) + 1;
			$failed            += 'failed' === $outcome ? 1 : 0;
		}
		foreach ( $counts as $outcome => $count ) {
			\WP_CLI::log( sprintf( '%s: %d', $outcome, $count ) );
		}
		if ( $failed > 0 ) {
			\WP_CLI::halt( 2 );
		}
		\WP_CLI::success( 'Attachments processed.' );
	}

	/**
	 * Cache management.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : clear
	 *
	 * [--scope=<scope>]
	 * : all, html or templates (all scopes share one generation counter).
	 * ---
	 * default: all
	 * ---
	 */
	public function cache( array $args, array $assoc_args ): void {
		if ( 'clear' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Use: wp sw-cloudcalendar cache clear [--scope=all]' );
		}
		$this->services()->cache()->clear();
		\WP_CLI::success( 'Calendar caches cleared.' );
	}

	/**
	 * Prints diagnostics (no secrets, no event contents).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : json or summary.
	 * ---
	 * default: summary
	 * ---
	 */
	public function diagnostics( array $args, array $assoc_args ): void {
		$data = ( new Diagnostics( $this->services() ) )->collect();
		if ( 'json' === ( $assoc_args['format'] ?? 'summary' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		\WP_CLI::log( sprintf( 'Plugin %s, schema %d, PHP %s', $data['versions']['plugin'], $data['versions']['db_schema'], $data['versions']['php'] ) );
		\WP_CLI::log( sprintf( 'Encryption key: %s', $data['security']['encryption_key'] ) );
		\WP_CLI::log( sprintf( 'Storage: %s (%s %s)', $data['storage']['safe'] ? 'safe' : 'NOT SAFE', $data['storage']['method'], $data['storage']['reason'] ) );
		\WP_CLI::log( sprintf( 'Cron: mode %s, next tick %s', $data['cron']['mode'], (string) $data['cron']['next_tick'] ) );
		foreach ( $data['sources'] as $source ) {
			\WP_CLI::log( sprintf( '- #%d %s: %s, last success %s, error %s', $source['id'], $source['name'], $source['status'], (string) $source['last_success_at'], (string) $source['last_error_code'] ) );
		}
		if ( ! $data['storage']['safe'] || 'wp_salts_fallback' === $data['security']['encryption_key'] ) {
			\WP_CLI::warning( 'Please review the warnings above.' );
		}
	}

	/**
	 * Processes the job queue (for a real cron: `* * * * * wp sw-cloudcalendar queue run`).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : run
	 *
	 * [--seconds=<seconds>]
	 * : Maximum run time.
	 * ---
	 * default: 50
	 * ---
	 */
	public function queue( array $args, array $assoc_args ): void {
		if ( 'run' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Use: wp sw-cloudcalendar queue run [--seconds=50]' );
		}
		$result = ( new QueueRunner( $this->services() ) )->run( max( 5, (int) ( $assoc_args['seconds'] ?? 50 ) ) );
		\WP_CLI::log( sprintf( 'Scheduled %d, processed %d, failed %d job(s).', $result['scheduled'], $result['processed'], $result['failed'] ) );
		if ( $result['failed'] > 0 ) {
			\WP_CLI::halt( 2 );
		}
	}
}
