<?php
/**
 * WP-CLI commands (SPECIFICATIONS.md §15) against a recording WP_CLI stand-in: output, exit codes, no secrets.
 */

namespace WP_CLI\Utils {

	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		/**
		 * @param array<int, array<string, mixed>> $items
		 * @param list<string>                     $fields
		 */
		function format_items( string $format, array $items, array $fields ): void {
			\WP_CLI::$items = array(
				'format' => $format,
				'items'  => $items,
				'fields' => $fields,
			);
		}
	}
}

namespace {

	use SW\Calendar\Application\Http\HttpResponse;
	use SW\Calendar\Domain\Source\Visibility;
	use SW\Calendar\Presentation\Cli\CalendarCommand;

	if ( ! class_exists( 'WP_CLI' ) ) {
		/** Records output; error() and halt() stop like the real WP_CLI by throwing. */
		final class WP_CLI {
			/** @var list<array{string, string}> */
			public static array $out = array();
			/** @var array<string, mixed> */
			public static array $items = array();

			public static function reset(): void {
				self::$out   = array();
				self::$items = array();
			}

			public static function error( string $message ): void {
				self::$out[] = array( 'error', $message );
				throw new SWC_Cli_Exit( 1 );
			}

			public static function halt( int $code ): void {
				throw new SWC_Cli_Exit( $code );
			}

			public static function success( string $message ): void {
				self::$out[] = array( 'success', $message );
			}

			public static function warning( string $message ): void {
				self::$out[] = array( 'warning', $message );
			}

			public static function log( string $message ): void {
				self::$out[] = array( 'log', $message );
			}

			public static function line( string $message ): void {
				self::$out[] = array( 'line', $message );
			}
		}

		final class SWC_Cli_Exit extends RuntimeException {
			public function __construct( public readonly int $exit_code ) {
				parent::__construct( 'exit ' . $exit_code );
			}
		}
	}

	final class CalendarCommandTest extends SWC_TestCase {

		private CalendarCommand $cli;

		public function set_up(): void {
			parent::set_up();
			WP_CLI::reset();
			$this->cli = new CalendarCommand();
		}

		/**
		 * @param list<string>          $args
		 * @param array<string, string> $assoc
		 */
		private function run_cli( string $command, array $args = array(), array $assoc = array() ): int {
			try {
				$this->cli->$command( $args, $assoc );
			} catch ( SWC_Cli_Exit $exit ) {
				return $exit->exit_code;
			}
			return 0;
		}

		/** @return list<string> */
		private function messages( string $type ): array {
			return array_values( array_map( static fn ( $o ) => $o[1], array_filter( WP_CLI::$out, static fn ( $o ) => $o[0] === $type ) ) );
		}

		public function test_sources_list_formats_rows(): void {
			$source = $this->source( $this->ics_connection() );
			$this->sync( $source );

			$this->assertSame( 0, $this->run_cli( 'sources', array(), array( 'format' => 'json' ) ) );
			$this->assertSame( 'json', WP_CLI::$items['format'] );
			$row = WP_CLI::$items['items'][0];
			$this->assertSame( $source, $row['id'] );
			$this->assertSame( '-', $row['last_error'] );
			$this->assertGreaterThan( 0, $row['occurrences'] );
			$this->assertSame( array_keys( $row ), WP_CLI::$items['fields'] );

			$this->assertSame( 1, $this->run_cli( 'sources', array( 'delete' ) ) );
		}

		public function test_connection_test_reports_success_and_failure(): void {
			$connection = $this->ics_connection();
			$this->assertSame( 1, $this->run_cli( 'connections', array( 'ping' ) ) );

			$this->http->push( 200, $this->feed() );
			$this->assertSame( 0, $this->run_cli( 'connections', array( 'test' ), array( 'id' => (string) $connection ) ) );
			$this->assertCount( 1, $this->messages( 'success' ) );

			$this->http->push( 404, 'missing' );
			$this->assertSame( 1, $this->run_cli( 'connections', array( 'test' ), array( 'id' => (string) $connection ) ) );
			$this->assertMatchesRegularExpression( '/\(.+\)$/', $this->messages( 'error' )[1], 'Error code is appended' );

			$this->assertSame( 1, $this->run_cli( 'connections', array( 'test' ), array( 'id' => '999999' ) ), 'Unknown connection' );
		}

		public function test_sync_requires_a_selection_and_reports_partial_failures(): void {
			$this->assertSame( 1, $this->run_cli( 'sync' ) );

			$ok     = $this->source( $this->ics_connection() );
			$broken = $this->source( $this->ics_connection() );
			$this->http->push( 200, $this->feed() );
			$this->assertSame( 0, $this->run_cli( 'sync', array(), array( 'source' => (string) $ok ) ) );
			$this->assertStringStartsWith( 'Source ' . $ok . ': success', $this->messages( 'log' )[0] );
			$this->assertSame( array( '1 source(s) processed.' ), $this->messages( 'success' ) );

			WP_CLI::reset();
			$this->http->push( 200, $this->feed() );
			$this->http->push( 500, 'down' );
			$this->assertSame( 2, $this->run_cli( 'sync', array(), array( 'all' => '1', 'full' => '1' ) ), 'Partial failure' );
			$this->assertCount( 1, $this->messages( 'warning' ) );
			$this->assertStringContainsString( 'Source ' . $broken . ': failed', $this->messages( 'warning' )[0] );

			WP_CLI::reset();
			$this->http->push( 500, 'down' );
			$this->assertSame( 1, $this->run_cli( 'sync', array(), array( 'source' => (string) $broken ) ), 'Complete failure' );

			WP_CLI::reset();
			$this->assertSame( 0, $this->run_cli( 'sync', array(), array( 'due' => '1' ) ) );
		}

		public function test_attachment_commands(): void {
			$this->assertSame( 1, $this->run_cli( 'attachments', array( 'sync' ) ), 'Source is required' );

			$this->assertSame( 0, $this->run_cli( 'attachments', array( 'prune' ), array( 'dry-run' => '1' ) ) );
			$this->assertSame( array( '0 file(s) would be deleted.' ), $this->messages( 'log' ) );
			$this->assertSame( 0, $this->run_cli( 'attachments', array( 'prune' ) ) );
			$this->assertSame( array( '0 file(s) deleted, 0 temporary file(s) removed.' ), $this->messages( 'success' ) );

			$source = $this->source(
				$this->ics_connection(),
				Visibility::Authenticated,
				array(),
				array(
					'attachments' => array(
						'sync_attachments'   => true,
						'public_only'        => false,
						'allowed_mime_types' => array( 'image/png', 'application/pdf' ),
					),
				)
			);
			$this->http->push( 200, $this->feed( 'attachments.ics' ) );
			$this->assertSame( 'success', $this->services->sync()->sync( $source )->status );
			$this->assertNotSame( array(), $this->services->attachments()->pending_refs( $source ) );

			WP_CLI::reset();
			for ( $i = 0; $i < 20; $i++ ) {
				$this->http->queue[] = static fn () => new HttpResponse( 500, array(), 'down', 4 );
			}
			$this->assertSame( 2, $this->run_cli( 'attachments', array( 'sync' ), array( 'source' => (string) $source ) ) );
			$this->assertNotSame( array(), $this->messages( 'log' ), 'Outcome counts are printed' );
		}

		public function test_cache_clear_bumps_generation(): void {
			$this->assertSame( 1, $this->run_cli( 'cache' ) );
			$before = $this->services->cache()->generation();
			$this->assertSame( 0, $this->run_cli( 'cache', array( 'clear' ) ) );
			$this->assertNotSame( $before, $this->services->cache()->generation() );
		}

		public function test_diagnostics_summary_and_json(): void {
			$source = $this->source( $this->ics_connection() );
			$this->sync( $source );
			update_option( 'sw_calendar_storage', array( 'safe' => false, 'method' => 'http_probe', 'reason' => 'publicly_accessible', 'checked_at' => time(), 'root' => SW_CALENDAR_PRIVATE_STORAGE_PATH ) );

			$this->assertSame( 0, $this->run_cli( 'diagnostics' ) );
			$log = implode( "\n", $this->messages( 'log' ) );
			$this->assertStringContainsString( 'Plugin ' . SW_CALENDAR_VERSION, $log );
			$this->assertStringContainsString( 'Storage: NOT SAFE (http_probe publicly_accessible)', $log );
			$this->assertStringContainsString( '- #' . $source . ' ', $log );
			$this->assertSame( array( 'Please review the warnings above.' ), $this->messages( 'warning' ) );

			WP_CLI::reset();
			$this->assertSame( 0, $this->run_cli( 'diagnostics', array(), array( 'format' => 'json' ) ) );
			$json = json_decode( $this->messages( 'line' )[0], true );
			$this->assertSame( SW_CALENDAR_VERSION, $json['versions']['plugin'] );
		}

		public function test_queue_run(): void {
			$this->assertSame( 1, $this->run_cli( 'queue' ) );
			$this->assertSame( 0, $this->run_cli( 'queue', array( 'run' ), array( 'seconds' => '1' ) ) );
			$this->assertMatchesRegularExpression( '/^Scheduled \d+, processed \d+, failed 0 job\(s\)\.$/', $this->messages( 'log' )[0] );
		}
	}
}
