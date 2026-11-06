<?php

use SW\Calendar\Domain\Template\EditorMode;
use SW\Calendar\Domain\Template\TemplateKind;
use SW\Calendar\Infrastructure\WordPress\Settings;
use SW\Calendar\Presentation\Admin\TemplateManager;

/**
 * Template administration (presets, reference, validation, save rules, preview) and the system admin API
 * (storage check, cache, logs, audit, runs, scheduling, settings, metadata).
 */
final class TemplateAndSystemAdminTest extends SWC_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->admin();
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function create_template( array $body ): WP_REST_Response {
		return $this->rest( 'POST', '/admin/templates', array(), $body + array( 'name' => 'T', 'kind' => 'event', 'body' => '<p>{{event.title}}</p>', 'css' => '' ) );
	}

	public function test_presets_reference_and_install(): void {
		$presets = $this->rest( 'GET', '/admin/templates/presets' )->get_data();
		$this->assertNotEmpty( $presets );
		$first = $presets[0];
		$this->assertSame( array( 'container', 'event', 'detail' ), array_keys( $first['templates'] ) );
		$this->assertNotSame( '', $first['templates']['event']['body'] );

		$reference = $this->rest( 'GET', '/admin/templates/reference' )->get_data();
		$this->assertSame( array( 'container', 'event', 'detail' ), array_keys( $reference ) );

		$installed = $this->rest( 'POST', '/admin/templates/presets/' . $first['key'] . '/install' );
		$this->assertSame( 201, $installed->get_status() );
		$this->assertCount( 3, $installed->get_data() );
		$this->assertSame( $first['key'], $installed->get_data()[0]['preset_key'] );
		$this->assertStringContainsString( 'Container', $installed->get_data()[0]['name'] );
		$this->assertArrayNotHasKey( 'body', $installed->get_data()[0] );
		$this->assertSame( 404, $this->rest( 'POST', '/admin/templates/presets/no-such-preset/install' )->get_status() );

		// Seeding is skipped once templates exist.
		$count = $this->services->templates()->count();
		( new TemplateManager( $this->services ) )->seed_presets();
		$this->assertSame( $count, $this->services->templates()->count() );

		// A fresh installation receives editable copies of every preset.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . \SW\Calendar\Infrastructure\Database\Schema::table( 'templates' ) );
		( new TemplateManager( $this->services ) )->seed_presets();
		$this->assertSame( 3 * count( $presets ), $this->services->templates()->count() );
	}

	public function test_validate_endpoint_reports_template_and_css_errors(): void {
		$ok = $this->rest( 'POST', '/admin/templates/validate', array(), array( 'kind' => 'event', 'body' => '<p>{{event.title}}</p>', 'css' => 'p{color:red}' ) )->get_data();
		$this->assertTrue( $ok['valid'] );
		$this->assertNull( $ok['css_error'] );

		$bad = $this->rest( 'POST', '/admin/templates/validate', array(), array( 'kind' => 'event', 'body' => '<p>{{#unclosed}}</p>', 'css' => 'p{background:url(https://evil.example/x.png)}' ) )->get_data();
		$this->assertFalse( $bad['valid'] );
		$this->assertNotEmpty( $bad['errors'] );
		$this->assertNotNull( $bad['css_error'] );

		$this->assertSame( 400, $this->rest( 'POST', '/admin/templates/validate', array(), array( 'kind' => 'page' ) )->get_status() );
	}

	public function test_save_rules(): void {
		$this->assertSame( array( 'kind' => 'invalid' ), $this->create_template( array( 'kind' => 'page' ) )->get_data()['data']['params'] );
		$errors = $this->create_template( array( 'name' => ' ', 'editor_mode' => 'wysiwyg', 'visual_schema' => 'not-an-object' ) )->get_data()['data']['params'];
		$this->assertSame( array( 'name' => 'invalid', 'editor_mode' => 'invalid', 'visual_schema' => 'invalid' ), $errors );
		$this->assertSame( 404, $this->rest( 'PUT', '/admin/templates/999999', array(), array( 'name' => 'x' ) )->get_status() );
		$this->assertSame( 404, $this->rest( 'DELETE', '/admin/templates/999999' )->get_status() );

		// Visual templates keep their schema, HTML templates never store one.
		$schema = array( 'blocks' => array( array( 'type' => 'title' ) ) );
		$visual = $this->create_template( array( 'editor_mode' => 'visual', 'visual_schema' => $schema, 'body' => "<p>\r\n{{event.title}}</p>" ) );
		$this->assertSame( 201, $visual->get_status(), (string) wp_json_encode( $visual->get_data() ) );
		$this->assertSame( $schema, $visual->get_data()['visual_schema'] );
		$this->assertSame( "<p>\n{{event.title}}</p>", $visual->get_data()['body'], 'Line endings are normalized' );
		$id = $visual->get_data()['id'];

		$html = $this->rest( 'PUT', '/admin/templates/' . $id, array(), array( 'editor_mode' => 'html', 'kind' => 'detail' ) )->get_data();
		$this->assertSame( 'html', $html['editor_mode'] );
		// The old schema is dropped (stored as '' on update because wpdb::prepare() turns NULL into '', read back as []).
		$this->assertEmpty( $html['visual_schema'] );
		$this->assertSame( 'event', $html['kind'], 'The kind of an existing template cannot change' );
		$this->assertSame( array(), $html['used_by'] );

		$this->assertSame( 200, $this->rest( 'DELETE', '/admin/templates/' . $id )->get_status() );
		$this->assertNull( $this->services->templates()->find( $id ) );
		$this->assertContains( 'template_deleted', array_column( $this->services->audit()->recent( 5 ), 'action' ) );
	}

	public function test_template_list_and_detail(): void {
		$id   = $this->create_template( array( 'name' => 'Liste' ) )->get_data()['id'];
		$list = $this->rest( 'GET', '/admin/templates', array( 'kind' => 'event' ) )->get_data();
		$this->assertContains( $id, array_column( $list['items'], 'id' ) );
		$this->assertSame( array( 'event' ), array_values( array_unique( array_column( $list['items'], 'kind' ) ) ) );
		$this->assertSame( 'Liste', $this->rest( 'GET', '/admin/templates/' . $id )->get_data()['name'] );
		$this->assertSame( 404, $this->rest( 'GET', '/admin/templates/999999' )->get_status() );
		$this->assertSame( 'Event', TemplateManager::kind_label( TemplateKind::Event ) );
		$this->assertSame( 'Detail', TemplateManager::kind_label( TemplateKind::Detail ) );
		$this->assertSame( EditorMode::Html, $this->services->templates()->find( $id )->editorMode );
	}

	public function test_preview_reports_errors_per_kind_and_renders_month(): void {
		$errors = $this->rest(
			'POST',
			'/admin/templates/preview',
			array(),
			array(
				'mode'   => 'list',
				'event'  => array( 'body' => '{{#broken}}' ),
				'detail' => array( 'body' => '<p>x</p>', 'css' => 'p{background:url(https://evil.example/a.png)}' ),
			)
		)->get_data();
		$this->assertSame( '', $errors['html'] );
		$this->assertArrayHasKey( 'event', (array) $errors['errors'] );
		$this->assertSame( 'unsafe_css', ( (array) $errors['errors'] )['detail'][0]['code'] );

		$month = $this->rest( 'POST', '/admin/templates/preview', array(), array( 'mode' => 'month', 'event' => array( 'css' => '.x{color:red}' ) ) );
		$this->assertSame( 200, $month->get_status() );
		$this->assertSame( 'no-store', $month->get_headers()['Cache-Control'] );
		$this->assertNotSame( '', $month->get_data()['html'] );
		$this->assertStringContainsString( '.swc-preview-event .x{color:red}', (string) wp_json_encode( $month->get_data()['css'] ) );
	}

	public function test_diagnostics_are_not_cached(): void {
		$response = $this->rest( 'GET', '/admin/diagnostics' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertNotEmpty( $response->get_data() );
	}

	public function test_storage_check_hides_the_server_path(): void {
		$data = $this->rest( 'POST', '/admin/diagnostics/storage-check' )->get_data();
		$this->assertArrayNotHasKey( 'root', $data );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $data['checked_at'] );
	}

	public function test_cache_clear_logs_audit_and_runs(): void {
		$response = $this->rest( 'POST', '/admin/cache/clear', array(), array( 'scope' => 'HTML Cache' ) );
		$this->assertTrue( $response->get_data()['cleared'] );
		$audit = $this->rest( 'GET', '/admin/audit', array( 'page' => 1, 'per_page' => 5 ) )->get_data();
		$this->assertSame( 'cache_cleared', $audit['items'][0]['action'] );
		$this->assertSame( 'htmlcache', $audit['items'][0]['context']['scope'] );
		$this->assertSame( wp_get_current_user()->display_name, $audit['items'][0]['user_name'] );

		$source = $this->source( $this->ics_connection() );
		$this->services->logger()->log( 'warning', 'test_warning', 'Warn', array( 'source_id' => $source ) );
		$this->services->logger()->log( 'error', 'test_error', 'Err' );
		$filtered = $this->rest( 'GET', '/admin/logs', array( 'page' => 1, 'per_page' => 10, 'level' => 'warning', 'source_id' => (string) $source ) )->get_data();
		$this->assertSame( 1, $filtered['total'] );
		$this->assertSame( 'test_warning', $filtered['items'][0]['code'] );
		$all = $this->rest( 'GET', '/admin/logs', array( 'page' => 1, 'per_page' => 1, 'level' => '' ) )->get_data();
		$this->assertGreaterThanOrEqual( 2, $all['total'] );
		$this->assertCount( 1, $all['items'] );

		$this->sync( $source );
		$runs = $this->rest( 'GET', '/admin/sync/runs', array( 'page' => 1, 'per_page' => 10 ) )->get_data();
		$this->assertSame( 1, $runs['total'] );
		$this->assertSame( 'success', $runs['items'][0]['status'] );
	}

	public function test_schedule_queues_due_sources_without_syncing(): void {
		$this->source( $this->ics_connection() );
		$blocked = static fn () => array( 'response' => array( 'code' => 200 ), 'body' => '' );
		add_filter( 'pre_http_request', $blocked );
		try {
			$response = $this->rest( 'POST', '/admin/sync/schedule' );
		} finally {
			remove_filter( 'pre_http_request', $blocked );
		}
		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['queued'] );
		$this->assertSame( array(), $this->http->requests, 'No provider request inside the admin request' );

		Settings::update( array( 'cron_mode' => 'system' ) );
		$this->assertSame( 202, $this->rest( 'POST', '/admin/sync/schedule' )->get_status() );
	}

	public function test_settings_round_trip_and_validation(): void {
		$this->assertSame( Settings::defaults()['cron_mode'], $this->rest( 'GET', '/admin/settings' )->get_data()['cron_mode'] );
		$invalid = $this->rest( 'PUT', '/admin/settings', array(), array( 'log_level' => 'loud', 'nope' => 1 ) );
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertSame( array( 'log_level' => 'invalid', 'nope' => 'unknown' ), $invalid->get_data()['data']['params'] );

		$saved = $this->rest( 'PUT', '/admin/settings', array(), array( 'log_level' => 'debug', 'log_retention_days' => 7 ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'debug', $saved->get_data()['log_level'] );
		$this->assertSame( 7, $saved->get_data()['log_retention_days'] );
		$this->assertContains( 'settings_updated', array_column( $this->services->audit()->recent( 5 ), 'action' ) );
	}

	public function test_meta_describes_the_admin_options(): void {
		$meta = $this->rest( 'GET', '/admin/meta' )->get_data();
		$this->assertSame( array( 'oauth' ), $meta['auth_modes']['microsoft'] );
		$this->assertContains( 'none', $meta['auth_modes']['ics'] );
		$this->assertArrayHasKey( 'administrator', $meta['roles'] );
		$this->assertContains( 'Europe/Berlin', $meta['timezones'] );
		$this->assertSame( 'Europe/Berlin', $meta['site_timezone'] );
		$this->assertStringContainsString( 'oauth/callback', $meta['redirect_uri'] );
		$this->assertIsBool( $meta['storage_safe'] );
		$this->assertIsBool( $meta['encryption_fallback'] );
		$this->assertGreaterThan( 0, $meta['max_window_days'] );
	}

	public function test_system_endpoints_require_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( array( array( 'GET', '/admin/meta' ), array( 'GET', '/admin/settings' ), array( 'POST', '/admin/cache/clear' ), array( 'GET', '/admin/templates/presets' ) ) as [ $method, $path ] ) {
			$this->assertSame( 403, $this->rest( $method, $path )->get_status(), $path );
		}
	}
}
