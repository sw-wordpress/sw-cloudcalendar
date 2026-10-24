<?php
/**
 * WordPress admin integration: the "Calendar" menu hosting the Vue/Quasar single page application.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Admin;

use SW\Calendar\Infrastructure\WordPress\Capabilities;

final class AdminPage {

	public const SLUG    = 'sw-cloudcalendar';
	private const HANDLE = 'sw-cloudcalendar-admin';

	private string $hook = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 2 );
	}

	public function add_menu(): void {
		$this->hook = (string) add_menu_page(
			__( 'Calendar', 'sw-cloudcalendar' ),
			__( 'Calendar', 'sw-cloudcalendar' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-calendar-alt',
			26
		);
		// Sub pages are routes of the SPA (hash history), see apps/admin/src/router.
		$routes = array(
			''             => __( 'Dashboard', 'sw-cloudcalendar' ),
			'/connections' => __( 'Connections', 'sw-cloudcalendar' ),
			'/sources'     => __( 'Sources', 'sw-cloudcalendar' ),
			'/views'       => __( 'Views', 'sw-cloudcalendar' ),
			'/templates'   => __( 'Templates', 'sw-cloudcalendar' ),
			'/sync'        => __( 'Synchronisation', 'sw-cloudcalendar' ),
			'/settings'    => __( 'Settings', 'sw-cloudcalendar' ),
			'/diagnostics' => __( 'Diagnostics', 'sw-cloudcalendar' ),
		);
		foreach ( $routes as $route => $label ) {
			add_submenu_page( self::SLUG, $label, $label, Capabilities::MANAGE, '' === $route ? self::SLUG : self::SLUG . '#' . $route, '' === $route ? array( $this, 'render' ) : '__return_null' );
		}
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sw-cloudcalendar' ), 403 );
		}
		if ( ! is_readable( SW_CALENDAR_DIR . 'assets/admin/app.js' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'The calendar administration has not been built. Install the release package or run "npm run build" in apps/admin.', 'sw-cloudcalendar' ) . '</p></div></div>';
			return;
		}
		echo '<div class="wrap swc-wrap"><div id="sw-cloudcalendar-app"></div><noscript>' . esc_html__( 'The calendar administration requires JavaScript.', 'sw-cloudcalendar' ) . '</noscript></div>';
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== $this->hook || ! is_readable( SW_CALENDAR_DIR . 'assets/admin/app.js' ) ) {
			return;
		}
		$version = (string) filemtime( SW_CALENDAR_DIR . 'assets/admin/app.js' );
		wp_enqueue_style( self::HANDLE, SW_CALENDAR_URL . 'assets/admin/app.css', array(), $version );
		wp_enqueue_script( self::HANDLE, SW_CALENDAR_URL . 'assets/admin/app.js', array(), $version, true );
		wp_add_inline_script( self::HANDLE, 'window.swcloudcalendarConfig = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * Configuration contract with the SPA (apps/admin/README.md).
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array(
			'restRoot'     => esc_url_raw( rest_url( 'sw-cloudcalendar/v1/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'locale'       => determine_locale(),
			'version'      => SW_CALENDAR_VERSION,
			'adminUrl'     => esc_url_raw( admin_url( 'admin.php?page=' . self::SLUG ) ),
			'capabilities' => current_user_can( Capabilities::MANAGE ) ? array( Capabilities::MANAGE ) : array(),
			'siteUrl'      => esc_url_raw( site_url() ),
			'pluginUrl'    => esc_url_raw( SW_CALENDAR_URL ),
		);
	}

	public function module_tag( string $tag, string $handle ): string {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		return str_replace( '<script ', '<script type="module" ', $tag );
	}
}
