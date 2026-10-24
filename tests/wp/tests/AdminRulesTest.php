<?php

use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;

/**
 * Publication decisions, template validation and REST admin contracts (E2E-04, E2E-08).
 */
final class AdminRulesTest extends SWC_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->admin();
	}

	public function test_new_sources_are_drafts(): void {
		$connection = $this->ics_connection();
		$response   = $this->rest( 'POST', '/admin/sources', array(), array( 'connection_id' => $connection, 'external_id' => 'feed', 'name' => 'Neu', 'visibility' => 'public', 'timezone' => 'Europe/Berlin' ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'draft', $response->get_data()['status'] );
		$this->assertFalse( $response->get_data()['requires_confirmation'], 'Unauthenticated ICS feeds are not private at the provider' );
	}

	public function test_private_provider_calendar_requires_publication_confirmation(): void {
		$oauth  = $this->services->connections()->create( Provider::Google, 'G', AuthMode::OAuth, ConnectionStatus::Connected, array( 'client_id' => 'x' ) );
		$body   = array( 'connection_id' => $oauth, 'external_id' => 'primary', 'name' => 'Privat', 'visibility' => 'public', 'timezone' => 'Europe/Berlin' );
		$denied = $this->rest( 'POST', '/admin/sources', array(), $body );
		$this->assertSame( 400, $denied->get_status() );
		$this->assertSame( 'required', $denied->get_data()['data']['params']['confirm_publication'] );

		$granted = $this->rest( 'POST', '/admin/sources', array(), $body + array( 'confirm_publication' => true ) );
		$this->assertSame( 201, $granted->get_status() );
		$confirmation = $granted->get_data()['settings']['publication_confirmation'];
		$this->assertSame( get_current_user_id(), $confirmation['user_id'] );
		$audit = $this->services->audit()->recent( 5 );
		$this->assertContains( 'source_publication_confirmed', array_column( $audit, 'action' ) );

		// Posting a forged confirmation structure is ignored.
		$forged = $this->rest( 'POST', '/admin/sources', array(), array_merge( $body, array( 'external_id' => 'other', 'settings' => array( 'publication_confirmation' => $confirmation ) ) ) );
		$this->assertSame( 400, $forged->get_status() );
	}

	public function test_private_attachments_on_public_source_require_second_confirmation(): void {
		$source = $this->source( $this->ics_connection() );
		$denied = $this->rest( 'PUT', '/admin/sources/' . $source, array(), array( 'settings' => array( 'attachments' => array( 'sync_attachments' => true, 'public_only' => false ) ) ) );
		$this->assertSame( 400, $denied->get_status() );
		$this->assertSame( 'required', $denied->get_data()['data']['params']['confirm_private_attachments'] );
		$ok = $this->rest( 'PUT', '/admin/sources/' . $source, array(), array( 'confirm_private_attachments' => true, 'settings' => array( 'attachments' => array( 'sync_attachments' => true, 'public_only' => false ) ) ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertTrue( $ok->get_data()['settings']['attachments']['publish_private_attachments_confirmed'] );
	}

	public function test_template_validation_and_in_use_protection(): void {
		$invalid = $this->rest( 'POST', '/admin/templates', array(), array( 'name' => 'X', 'kind' => 'event', 'editor_mode' => 'html', 'body' => '<p onclick="x()">{{event.title}}</p>', 'css' => '' ) );
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertSame( 'event_handler', $invalid->get_data()['data']['validation']['errors'][0]['code'] );

		$created = $this->rest( 'POST', '/admin/templates', array(), array( 'name' => 'Mein Event', 'kind' => 'event', 'editor_mode' => 'html', 'body' => '<p class="mine">{{event.title}}</p>', 'css' => '.mine{color:red}' ) );
		$this->assertSame( 201, $created->get_status() );
		$template = $created->get_data()['id'];

		$source = $this->source( $this->ics_connection() );
		$this->sync( $source );
		$view = $this->rest( 'POST', '/admin/views', array(), array( 'name' => 'V', 'source_ids' => array( $source ), 'display_mode' => 'list', 'event_template_id' => $template ) );
		$this->assertSame( 201, $view->get_status() );
		$html = do_shortcode( '[sw_calendar id="' . $view->get_data()['id'] . '" start="2026-10-01" end="2026-11-30"]' );
		$this->assertStringContainsString( '<p class="mine">', $html );
		$this->assertStringContainsString( '.swc-tpl-' . $template . ' .mine{color:red}', $html );

		$this->assertSame( 409, $this->rest( 'DELETE', '/admin/templates/' . $template )->get_status() );
		// Changing the template changes the output immediately (E2E-12).
		$this->rest( 'PUT', '/admin/templates/' . $template, array(), array( 'body' => '<p class="changed">{{event.title}}</p>' ) );
		$this->assertStringContainsString( 'class="changed"', do_shortcode( '[sw_calendar id="' . $view->get_data()['id'] . '" start="2026-10-01" end="2026-11-30"]' ) );
	}

	public function test_preview_uses_fixed_datasets_and_escapes_hostile_content(): void {
		$response = $this->rest( 'POST', '/admin/templates/preview', array(), array( 'mode' => 'list' ) );
		$this->assertSame( 200, $response->get_status() );
		$html = $response->get_data()['html'];
		$this->assertStringContainsString( 'Jugendfreizeit', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertSame( array(), $this->http->requests, 'No provider requests' );
	}

	public function test_view_rejects_unknown_sources_and_wrong_template_kind(): void {
		$template = $this->services->templates()->all( \SW\Calendar\Domain\Template\TemplateKind::Detail )[0];
		$response = $this->rest( 'POST', '/admin/views', array(), array( 'name' => 'V', 'source_ids' => array( 999 ), 'display_mode' => 'list', 'event_template_id' => $template->id ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unknown_source', $response->get_data()['data']['params']['source_ids'] );
		$this->assertSame( 'invalid_template', $response->get_data()['data']['params']['event_template_id'] );
	}

	public function test_oauth_state_is_single_use_and_user_bound(): void {
		$connection = $this->services->connections()->create( Provider::Microsoft, 'M', AuthMode::OAuth, ConnectionStatus::Disconnected, array( 'client_id' => 'cid', 'tenant_id' => 'contoso.onmicrosoft.com' ) );
		$start      = $this->rest( 'POST', '/admin/connections/' . $connection . '/authorize' );
		parse_str( (string) wp_parse_url( $start->get_data()['url'], PHP_URL_QUERY ), $query );
		$state = $query['state'];

		$redirect = $this->rest( 'GET', '/oauth/callback', array( 'state' => $state, 'code' => 'abc' ) );
		$this->assertSame( 302, $redirect->get_status() );
		$this->assertStringContainsString( 'oauth_state=', $redirect->get_headers()['Location'] );

		// Another administrator cannot complete it.
		$this->admin();
		$this->assertSame( 403, $this->rest( 'POST', '/admin/oauth/complete', array( 'state' => $state ) )->get_status() );
		// And it is consumed now.
		$this->assertSame( 403, $this->rest( 'POST', '/admin/oauth/complete', array( 'state' => $state ) )->get_status() );
		$replay = $this->rest( 'GET', '/oauth/callback', array( 'state' => $state, 'code' => 'abc' ) );
		$this->assertStringContainsString( 'invalid_state', $replay->get_headers()['Location'] );
	}
}
