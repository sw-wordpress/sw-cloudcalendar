<?php
/**
 * Plugin bootstrap: wires WordPress hooks. Business logic lives in services.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar;

use SW\Calendar\Infrastructure\Database\Schema;
use SW\Calendar\Infrastructure\WordPress\Capabilities;
use SW\Calendar\Infrastructure\WordPress\QueueRunner;
use SW\Calendar\Presentation\Admin\AdminPage;
use SW\Calendar\Presentation\Admin\TemplateManager;
use SW\Calendar\Presentation\Block\CalendarBlock;
use SW\Calendar\Presentation\Frontend\CalendarOutput;
use SW\Calendar\Presentation\Rest\ConnectionsController;
use SW\Calendar\Presentation\Rest\PublicController;
use SW\Calendar\Presentation\Rest\SourcesController;
use SW\Calendar\Presentation\Rest\SystemController;
use SW\Calendar\Presentation\Rest\TemplatesController;
use SW\Calendar\Presentation\Rest\ViewsController;
use SW\Calendar\Presentation\Shortcode\CalendarShortcode;

final class Plugin {

	private static ?Plugin $instance = null;
	private bool $booted             = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'plugins_loaded', array( $this, 'maybe_migrate' ), 5 );
		add_action( 'init', array( $this, 'register_frontend' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_filter( 'cron_schedules', array( QueueRunner::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- five minute sync tick.
		add_action( QueueRunner::HOOK_TICK, array( $this, 'cron_tick' ) );
		add_action( QueueRunner::HOOK_DAILY, array( $this, 'cron_daily' ) );
		add_action( 'init', array( $this, 'ensure_cron' ), 20 );
		add_action( 'wp_ajax_sw_calendar_rest_nonce', array( CalendarOutput::class, 'ajax_nonce' ) );
		add_action( 'wp_ajax_nopriv_sw_calendar_rest_nonce', array( CalendarOutput::class, 'ajax_nonce' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy' ) );
		add_action( 'wp_initialize_site', array( $this, 'initialize_site' ), 20 );

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'sw-cloudcalendar', Presentation\Cli\CalendarCommand::class );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'sw-cloudcalendar', false, dirname( plugin_basename( SW_CALENDAR_FILE ) ) . '/languages' );
	}

	/** Cheap version comparison; migrations are idempotent. Also covers sites created after a network activation. */
	public function maybe_migrate(): void {
		if ( Schema::needs_migration() && Schema::migrate() ) {
			Capabilities::grant_defaults();
			( new TemplateManager( Services::instance() ) )->seed_presets();
		}
	}

	public function initialize_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( SW_CALENDAR_FILE ) ) ) {
			switch_to_blog( (int) $site->blog_id );
			try {
				Lifecycle::activate_site();
			} finally {
				restore_current_blog();
			}
		}
	}

	public function register_frontend(): void {
		CalendarOutput::register_assets();
		( new CalendarShortcode() )->register();
		( new CalendarBlock() )->register();
	}

	public function register_rest(): void {
		$services = Services::instance();
		foreach ( array(
			new PublicController( $services ),
			new ConnectionsController( $services ),
			new SourcesController( $services ),
			new ViewsController( $services ),
			new TemplatesController( $services ),
			new SystemController( $services ),
		) as $controller ) {
			$controller->register_routes();
		}
	}

	public function ensure_cron(): void {
		if ( ! wp_doing_ajax() && ! wp_is_serving_rest_request() ) {
			QueueRunner::ensure_scheduled();
		}
	}

	public function cron_tick(): void {
		( new QueueRunner( Services::instance() ) )->cron_tick();
	}

	public function cron_daily(): void {
		( new QueueRunner( Services::instance() ) )->cron_daily();
	}

	public function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'SW CloudCalendar', 'sw-cloudcalendar' ),
			wp_kses_post(
				'<p>' . __( 'This site shows appointments from external calendars (Google Calendar, Microsoft 365, ICS feeds). Appointment data and, if enabled, attachments are copied to this site and kept until they leave the configured synchronisation window, the calendar source is deleted or the plugin data is removed. Visitors are not tracked by the calendar. Attachments are delivered by this site; no files are loaded from the cloud providers in the visitor\'s browser.', 'sw-cloudcalendar' ) . '</p>'
			)
		);
	}
}
