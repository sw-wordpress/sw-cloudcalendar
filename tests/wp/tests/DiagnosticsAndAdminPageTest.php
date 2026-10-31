<?php
/**
 * Diagnostics export (no secrets, states and counts only) and the admin menu hosting the SPA.
 */

use SW\Calendar\Infrastructure\WordPress\QueueRunner;
use SW\Calendar\Infrastructure\WordPress\StorageGuard;
use SW\Calendar\Infrastructure\WordPress\TransientCache;
use SW\Calendar\Presentation\Admin\AdminPage;
use SW\Calendar\Presentation\Admin\Diagnostics;

final class DiagnosticsAndAdminPageTest extends SWC_TestCase {

	public function tear_down(): void {
		wp_clear_scheduled_hook( QueueRunner::HOOK_TICK );
		parent::tear_down();
	}

	public function test_diagnostics_report_states_and_counts_without_secrets(): void {
		$this->admin();
		$connection = $this->ics_connection();
		$source     = $this->source( $connection );
		$this->sync( $source );
		update_option( TransientCache::STATS_OPTION, array( 'hits' => 3, 'misses' => 1, 'since' => '2026-10-01T00:00:00Z' ) );
		update_option( QueueRunner::LAST_TICK_OPTION, 1793000000 );
		update_option( StorageGuard::OPTION, array( 'checked_at' => 1793000000, 'safe' => true, 'method' => 'outside_document_root', 'reason' => '' ) );
		wp_clear_scheduled_hook( QueueRunner::HOOK_TICK );
		wp_schedule_single_event( 1793001000, QueueRunner::HOOK_TICK );

		$data = ( new Diagnostics( $this->services ) )->collect();

		$this->assertSame( SW_CALENDAR_VERSION, $data['versions']['plugin'] );
		$this->assertSame( array(), $data['environment']['missing_tables'] );
		$this->assertSame( 'dedicated_key', $data['security']['encryption_key'] );
		$this->assertContains( 'administrator', $data['security']['capability_holders'] );
		$this->assertSame( 'constant', $data['storage']['configured'] );
		$this->assertTrue( $data['storage']['safe'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', 1793000000 ), $data['storage']['checked_at'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', 1793000000 ), $data['cron']['last_tick'] );
		$this->assertNull( $data['cron']['last_maintenance'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', 1793001000 ), $data['cron']['next_tick'] );
		$this->assertSame( 3, $data['cache']['hits'] );
		$this->assertSame( 1, $data['cache']['misses'] );

		$this->assertCount( 1, $data['connections'] );
		$this->assertSame( array( 'id', 'name', 'provider', 'auth_mode', 'status' ), array_keys( $data['connections'][0] ) );
		$this->assertSame( 'ics', $data['connections'][0]['provider'] );
		$this->assertCount( 1, $data['sources'] );
		$this->assertSame( $source, $data['sources'][0]['id'] );
		$this->assertGreaterThan( 0, $data['sources'][0]['occurrences'] );
		$this->assertNotNull( $data['sources'][0]['last_success_at'] );
		$this->assertNotNull( $data['sources'][0]['last_run'] );

		$json = (string) wp_json_encode( $data );
		$this->assertStringNotContainsString( 'calendar.example.org', $json, 'Connection settings (feed URL) are never exported' );
		$this->assertStringNotContainsString( SW_CALENDAR_PRIVATE_STORAGE_PATH, $json, 'No storage paths' );
	}

	public function test_admin_menu_registers_spa_routes_for_managers(): void {
		$this->admin();
		$page = new AdminPage();
		$page->register();
		$this->assertSame( 10, has_action( 'admin_menu', array( $page, 'add_menu' ) ) );
		$this->assertSame( 10, has_filter( 'script_loader_tag', array( $page, 'module_tag' ) ) );

		global $submenu;
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$page->add_menu();
		$slugs = wp_list_pluck( $submenu[ AdminPage::SLUG ], 2 );
		$this->assertSame( AdminPage::SLUG, $slugs[0] );
		$this->assertContains( AdminPage::SLUG . '#/diagnostics', $slugs );
		$this->assertCount( 8, $slugs );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		if ( is_readable( SW_CALENDAR_DIR . 'assets/admin/app.js' ) ) {
			$this->assertStringContainsString( 'id="sw-cloudcalendar-app"', $html );
		} else {
			$this->assertStringContainsString( 'notice-error', $html );
		}
	}

	public function test_render_denies_users_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( WPDieException::class );
		( new AdminPage() )->render();
	}

	public function test_config_contract_and_module_tag(): void {
		$page   = new AdminPage();
		$guest  = $page->config();
		$this->assertSame( array(), $guest['capabilities'] );
		$this->assertStringEndsWith( 'sw-cloudcalendar/v1/', $guest['restRoot'] );
		$this->assertSame( SW_CALENDAR_VERSION, $guest['version'] );
		$this->assertStringContainsString( 'page=' . AdminPage::SLUG, $guest['adminUrl'] );

		$this->admin();
		$this->assertSame( array( 'manage_sw_calendar' ), $page->config()['capabilities'] );
		$this->assertNotEmpty( $page->config()['nonce'] );

		$tag = '<script src="app.js"></script>';
		$this->assertSame( '<script type="module" src="app.js"></script>', $page->module_tag( $tag, 'sw-cloudcalendar-admin' ) );
		$this->assertSame( $tag, $page->module_tag( $tag, 'other' ) );
	}

	public function test_enqueue_only_on_own_screen(): void {
		$this->admin();
		$page = new AdminPage();
		$page->add_menu();
		$page->enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( 'sw-cloudcalendar-admin', 'enqueued' ) );

		$hook = 'toplevel_page_' . AdminPage::SLUG;
		$page->enqueue( $hook );
		if ( ! is_readable( SW_CALENDAR_DIR . 'assets/admin/app.js' ) ) {
			$this->assertFalse( wp_script_is( 'sw-cloudcalendar-admin', 'enqueued' ) );
			return;
		}
		$this->assertTrue( wp_script_is( 'sw-cloudcalendar-admin', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'sw-cloudcalendar-admin', 'enqueued' ) );
		$inline = implode( '', (array) wp_scripts()->get_data( 'sw-cloudcalendar-admin', 'before' ) );
		$this->assertStringContainsString( 'window.swcloudcalendarConfig = ', $inline );
		wp_dequeue_script( 'sw-cloudcalendar-admin' );
		wp_dequeue_style( 'sw-cloudcalendar-admin' );
	}
}
