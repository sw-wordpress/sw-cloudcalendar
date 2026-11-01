<?php

use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Domain\Provider\CalendarDefinition;
use SW\Calendar\Domain\Provider\CalendarProviderInterface;
use SW\Calendar\Infrastructure\Provider\ProviderRegistry;
use SW\Calendar\Presentation\Admin\ConnectionManager;

/**
 * Connection administration over REST: validation per provider/auth mode, secrets, OAuth flow, test, calendars,
 * disconnect and delete. Provider HTTP is faked.
 */
final class ConnectionManagementTest extends SWC_TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->admin();
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, string>
	 */
	private function invalid_params( array $body, int $status = 400, string $method = 'POST', string $path = '/admin/connections' ): array {
		$response = $this->rest( $method, $path, array(), $body );
		$this->assertSame( $status, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return (array) ( $response->get_data()['data']['params'] ?? array() );
	}

	private function google( array $settings = array( 'client_id' => 'gid' ), ConnectionStatus $status = ConnectionStatus::Disconnected ): int {
		return $this->services->connections()->create( Provider::Google, 'G', AuthMode::OAuth, $status, $settings );
	}

	public function test_ics_connection_is_created_with_normalized_url_and_listed_without_secret_values(): void {
		$response = $this->rest(
			'POST',
			'/admin/connections',
			array(),
			array(
				'provider'  => 'ics',
				'auth_mode' => 'http_header',
				'name'      => ' Gemeinde ',
				// IP literal: the URL guard needs no DNS lookup.
				'settings'  => array( 'url' => 'webcal://93.184.215.14/feed.ics', 'max_feed_mb' => 5 ),
				'secrets'   => array( 'auth_header' => 'Bearer abc' ),
			)
		);
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'Gemeinde', $data['name'] );
		$this->assertSame( 'connected', $data['status'] );
		$this->assertSame( 'https://93.184.215.14/feed.ics', $data['settings']['url'] );
		$this->assertSame( 5, $data['settings']['max_feed_mb'] );
		$this->assertSame( array( 'auth_header' ), $data['secrets_set'] );
		$this->assertFalse( $data['authorized'] );
		$this->assertSame( 0, $data['source_count'] );
		$this->assertStringContainsString( 'sw-cloudcalendar/v1/oauth/callback', $data['redirect_uri'] );
		$this->assertStringNotContainsString( 'Bearer abc', (string) wp_json_encode( $this->rest( 'GET', '/admin/connections' )->get_data() ) );
		$this->assertSame( 'Gemeinde', $this->rest( 'GET', '/admin/connections/' . $data['id'] )->get_data()['name'] );
		$this->assertSame( 404, $this->rest( 'GET', '/admin/connections/999999' )->get_status() );
		$this->assertContains( 'connection_created', array_column( $this->services->audit()->recent( 5 ), 'action' ) );

		// Partial update keeps the URL, toggles allow_http and stores a new secret.
		$updated = $this->rest( 'PUT', '/admin/connections/' . $data['id'], array(), array( 'name' => 'Neu', 'settings' => array( 'allow_http' => 'yes' ), 'secrets' => array( 'auth_header' => 'Bearer new' ) ) );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 'Neu', $updated->get_data()['name'] );
		$this->assertTrue( $updated->get_data()['settings']['allow_http'] );
		$this->assertSame( 'https://93.184.215.14/feed.ics', $updated->get_data()['settings']['url'] );
		$this->assertSame( 'Bearer new', $this->services->credentials()->get( $data['id'] )['auth_header'] );
	}

	public function test_create_rejects_unknown_provider_and_auth_mode(): void {
		$this->assertSame( array( 'provider' => 'invalid' ), $this->invalid_params( array( 'provider' => 'caldav' ) ) );
		$this->assertSame( array( 'auth_mode' => 'invalid' ), $this->invalid_params( array( 'provider' => 'ics', 'auth_mode' => 'oauth' ) ) );
		$this->assertSame( array( 'id' => 'not_found' ), $this->invalid_params( array( 'name' => 'x' ), 404, 'PUT', '/admin/connections/999999' ) );
	}

	public function test_ics_settings_and_secrets_are_validated(): void {
		$errors = $this->invalid_params(
			array(
				'provider'  => 'ics',
				'auth_mode' => 'none',
				'name'      => str_repeat( 'x', 191 ),
				'settings'  => array( 'url' => 'https://127.0.0.1/feed.ics', 'max_feed_mb' => 51 ),
				'secrets'   => array( 'api_key' => 'nope' ),
			)
		);
		$this->assertSame( 'invalid', $errors['name'] );
		$this->assertSame( 'out_of_range', $errors['settings.max_feed_mb'] );
		$this->assertSame( 'unsafe_url', $errors['settings.url'] );
		$this->assertSame( 'not_allowed', $errors['secrets.api_key'] );

		$missing = $this->invalid_params( array( 'provider' => 'ics', 'auth_mode' => 'http_header', 'name' => 'H', 'settings' => array( 'url' => 'https://93.184.215.14/a.ics' ), 'secrets' => array( 'auth_header' => '  ' ) ) );
		$this->assertSame( 'required', $missing['secrets.auth_header'] );

		// Header injection through a secret is rejected on update (no "required" check for existing connections).
		$existing = $this->services->connections()->create( Provider::Ics, 'H', AuthMode::HttpHeader, ConnectionStatus::Connected, array( 'url' => 'https://93.184.215.14/a.ics' ) );
		$header   = $this->invalid_params( array( 'secrets' => array( 'auth_header' => "a\r\nX-Evil: 1" ) ), 400, 'PUT', '/admin/connections/' . $existing );
		$this->assertSame( array( 'secrets.auth_header' => 'invalid' ), $header );
		$too_long = $this->invalid_params( array( 'secrets' => array( 'auth_header' => str_repeat( 'a', 4097 ) ) ), 400, 'PUT', '/admin/connections/' . $existing );
		$this->assertSame( array( 'secrets.auth_header' => 'invalid' ), $too_long );
	}

	public function test_cloud_provider_settings_are_validated(): void {
		$google = $this->invalid_params( array( 'provider' => 'google', 'auth_mode' => 'oauth', 'name' => 'G', 'settings' => array( 'shared_calendars' => true ) ) );
		$this->assertSame( 'required', $google['settings.client_id'] );
		$this->assertSame( 'not_supported', $google['settings.shared_calendars'] );

		$api = $this->invalid_params( array( 'provider' => 'google', 'auth_mode' => 'api_key', 'name' => 'G' ) );
		$this->assertSame( array( 'secrets.api_key' => 'required' ), $api );

		$ms = $this->invalid_params( array( 'provider' => 'microsoft', 'auth_mode' => 'oauth', 'name' => 'M', 'settings' => array( 'client_id' => 'c', 'tenant_id' => 'bad tenant/../' ) ) );
		$this->assertSame( array( 'settings.tenant_id' => 'invalid' ), $ms );

		$created = $this->rest( 'POST', '/admin/connections', array(), array( 'provider' => 'microsoft', 'auth_mode' => 'oauth', 'name' => 'M', 'settings' => array( 'client_id' => 'c', 'tenant_id' => 'contoso.onmicrosoft.com', 'attachment_access' => 'on' ), 'secrets' => array( 'client_secret' => 's3cret' ) ) );
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'disconnected', $created->get_data()['status'], 'OAuth connections start without consent' );
		$this->assertTrue( $created->get_data()['settings']['attachment_access'] );
		$this->assertSame( array( 'client_secret' ), $created->get_data()['secrets_set'] );
	}

	public function test_changing_oauth_client_drops_tokens_and_requires_new_consent(): void {
		$id = $this->google( array( 'client_id' => 'old' ), ConnectionStatus::Connected );
		$this->services->credentials()->update( $id, array( 'client_secret' => 's', 'refresh_token' => 'r', 'access_token' => 'a', 'expires_at' => '9999999999' ) );
		$this->assertTrue( $this->rest( 'GET', '/admin/connections/' . $id )->get_data()['authorized'] );

		$unchanged = $this->rest( 'PUT', '/admin/connections/' . $id, array(), array( 'name' => 'Same' ) );
		$this->assertSame( 'connected', $unchanged->get_data()['status'] );

		$changed = $this->rest( 'PUT', '/admin/connections/' . $id, array(), array( 'settings' => array( 'client_id' => 'new' ) ) );
		$this->assertSame( 200, $changed->get_status() );
		$this->assertSame( 'disconnected', $changed->get_data()['status'] );
		$this->assertFalse( $changed->get_data()['authorized'] );
		$secrets = $this->services->credentials()->get( $id );
		$this->assertSame( 's', $secrets['client_secret'] );
		$this->assertArrayNotHasKey( 'refresh_token', $secrets );
	}

	public function test_authorization_requires_oauth_connection(): void {
		$ics = $this->ics_connection();
		$this->assertSame( array( 'auth_mode' => 'not_oauth' ), $this->invalid_params( array(), 400, 'POST', '/admin/connections/' . $ics . '/authorize' ) );
		$this->assertSame( 404, $this->rest( 'POST', '/admin/connections/999999/authorize' )->get_status() );
	}

	/**
	 * @return string State of a started authorization.
	 */
	private function start_and_callback( int $connection, string $code = 'the-code' ): string {
		$start = $this->rest( 'POST', '/admin/connections/' . $connection . '/authorize' );
		$this->assertSame( 200, $start->get_status() );
		$this->assertSame( 'connecting', $this->services->connections()->find( $connection )->status->value );
		parse_str( (string) wp_parse_url( $start->get_data()['url'], PHP_URL_QUERY ), $query );
		$redirect = $this->rest( 'GET', '/oauth/callback', array( 'state' => $query['state'], 'code' => $code ) );
		$this->assertStringContainsString( 'oauth_state=', $redirect->get_headers()['Location'] );
		return (string) $query['state'];
	}

	public function test_google_oauth_flow_stores_tokens_and_account(): void {
		$id = $this->google();
		$this->services->credentials()->update( $id, array( 'client_secret' => 'cs' ) );
		$state = $this->start_and_callback( $id );

		$this->http->push( 200, array( 'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600, 'id_token' => 'idt', 'scope' => 'calendar' ) );
		$this->http->push( 200, array( 'items' => array( array( 'id' => 'primary' ) ) ) );
		$this->http->push( 200, array( 'id' => 'owner@example.org' ) );
		$response = $this->rest( 'POST', '/admin/oauth/complete', array( 'state' => $state ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'connected', $response->get_data()['status'] );
		$this->assertSame( 'owner@example.org', $response->get_data()['account_ref'] );
		$this->assertTrue( $response->get_data()['authorized'] );

		$token_request = $this->http->requests[0];
		$this->assertSame( 'https://oauth2.googleapis.com/token', $token_request->url );
		parse_str( (string) $token_request->body, $form );
		$this->assertSame( 'authorization_code', $form['grant_type'] );
		$this->assertSame( 'the-code', $form['code'] );
		$this->assertSame( 'cs', $form['client_secret'] );
		$this->assertNotEmpty( $form['code_verifier'] );
		$this->assertSame( 'Bearer at', $this->http->requests[1]->headers['Authorization'] ?? null );
		$secrets = $this->services->credentials()->get( $id );
		$this->assertArrayNotHasKey( 'id_token', $secrets, 'ID tokens are never stored' );
		$this->assertSame( (string) ( $this->clock->now()->getTimestamp() + 3600 ), $secrets['expires_at'] );
		$this->assertContains( 'connection_authorized', array_column( $this->services->audit()->recent( 5 ), 'action' ) );
	}

	public function test_failed_code_exchange_marks_connection_failed(): void {
		$id    = $this->google();
		$state = $this->start_and_callback( $id );
		$this->http->push( 400, array( 'error' => 'invalid_grant' ) );
		$response = $this->rest( 'POST', '/admin/oauth/complete', array( 'state' => $state ) );
		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'failed', $this->services->connections()->find( $id )->status->value );
		$this->assertContains( 'oauth_failed', array_column( $this->services->logger()->recent( 5 ), 'code' ) );
	}

	public function test_exchange_without_refresh_token_is_rejected(): void {
		$id    = $this->google();
		$state = $this->start_and_callback( $id );
		$this->http->push( 200, array( 'access_token' => 'at' ) );
		$this->assertSame( 502, $this->rest( 'POST', '/admin/oauth/complete', array( 'state' => $state ) )->get_status() );
		$this->assertArrayNotHasKey( 'access_token', $this->services->credentials()->get( $id ) );
	}

	public function test_denied_or_unknown_callbacks(): void {
		$manager = new ConnectionManager( $this->services );
		$this->assertStringContainsString( 'oauth=invalid_state', $manager->accept_callback( '', 'code', '' ) );
		$this->assertStringContainsString( 'oauth=invalid_state', $manager->accept_callback( 'unknown', 'code', '' ) );

		$id    = $this->google();
		$start = $manager->start_authorization( $id );
		parse_str( (string) wp_parse_url( $start['url'], PHP_URL_QUERY ), $query );
		$this->assertStringContainsString( 'oauth=denied', $manager->accept_callback( $query['state'], '', 'access_denied' ) );
		$this->assertSame( 'failed', $this->services->connections()->find( $id )->status->value );
		$this->assertStringContainsString( 'oauth=invalid_state', $manager->accept_callback( $query['state'], 'code', '' ), 'Denied state is consumed' );

		$this->expectException( \SW\Calendar\Presentation\Admin\ValidationException::class );
		$manager->complete_authorization( 'unknown', get_current_user_id() );
	}

	public function test_connection_test_updates_account_and_recovers_failed_status(): void {
		$id = $this->services->connections()->create( Provider::Ics, 'Feed', AuthMode::None, ConnectionStatus::Failed, array( 'url' => 'https://calendar.example.org/feed.ics' ) );
		$this->http->push( 200, $this->feed() );
		$response = $this->rest( 'POST', '/admin/connections/' . $id . '/test' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertNull( $response->get_data()['error_code'] );
		$this->assertSame( 'connected', $this->services->connections()->find( $id )->status->value );

		$this->http->push( 500, 'down' );
		$failed = $this->rest( 'POST', '/admin/connections/' . $id . '/test' )->get_data();
		$this->assertFalse( $failed['ok'] );
		$this->assertNotNull( $failed['error_code'] );

		// An OAuth connection without refresh token is not marked connected by a successful API key style test.
		$oauth = $this->google( array( 'client_id' => 'gid' ), ConnectionStatus::ReauthRequired );
		$this->services->credentials()->update( $oauth, array( 'access_token' => 'at', 'expires_at' => (string) ( $this->clock->now()->getTimestamp() + 3600 ) ) );
		$this->http->push( 200, array( 'items' => array() ) );
		$this->http->push( 200, array( 'id' => 'me@example.org' ) );
		$this->assertTrue( $this->rest( 'POST', '/admin/connections/' . $oauth . '/test' )->get_data()['ok'] );
		$this->assertSame( 'reauth_required', $this->services->connections()->find( $oauth )->status->value );
		$this->assertSame( 'me@example.org', $this->services->connections()->find( $oauth )->accountRef );
	}

	public function test_calendars_are_listed_from_the_provider(): void {
		$id = $this->ics_connection();
		$this->http->push( 200, $this->feed() );
		$response = $this->rest( 'GET', '/admin/connections/' . $id . '/calendars' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'feed', $response->get_data()['items'][0]['external_id'] );
		$this->assertSame( 404, $this->rest( 'GET', '/admin/connections/999999/calendars' )->get_status() );
	}

	public function test_calendar_list_is_a_json_list_even_when_the_provider_returns_non_list_keys(): void {
		$id       = $this->ics_connection();
		$provider = $this->createMock( CalendarProviderInterface::class );
		$provider->method( 'listCalendars' )->willReturn(
			array(
				'b' => new CalendarDefinition( 'two', 'Two' ),
				7   => new CalendarDefinition( 'one', 'One' ),
			)
		);
		$this->services->set( 'providers', new ProviderRegistry( array( Provider::Ics->value => static fn () => $provider ) ) );
		$items = $this->rest( 'GET', '/admin/connections/' . $id . '/calendars' )->get_data()['items'];
		$this->assertSame( array( 0, 1 ), array_keys( $items ) );
		$this->assertSame( array( 'two', 'one' ), array_column( $items, 'external_id' ) );
		$this->assertStringStartsWith( '[', (string) wp_json_encode( $items ) );
	}

	public function test_disconnect_revokes_and_removes_tokens_even_when_revocation_fails(): void {
		$id = $this->google( array( 'client_id' => 'gid' ), ConnectionStatus::Connected );
		$this->services->credentials()->update( $id, array( 'client_secret' => 's', 'refresh_token' => 'rt', 'access_token' => 'at' ) );
		$this->http->push( 200, '' );
		$this->assertSame( 200, $this->rest( 'POST', '/admin/connections/' . $id . '/disconnect' )->get_status() );
		$this->assertSame( 'https://oauth2.googleapis.com/revoke', $this->http->requests[0]->url );
		$this->assertSame( 'token=rt', $this->http->requests[0]->body );
		$this->assertSame( array( 'client_secret' => 's' ), $this->services->credentials()->get( $id ) );
		$this->assertSame( 'disconnected', $this->services->connections()->find( $id )->status->value );

		// Revocation crashing (no response queued) is logged, the local tokens are removed anyway.
		$this->services->credentials()->update( $id, array( 'refresh_token' => 'rt2' ) );
		$this->assertSame( 200, $this->rest( 'POST', '/admin/connections/' . $id . '/disconnect' )->get_status() );
		$this->assertContains( 'oauth_revoke_failed', array_column( $this->services->logger()->recent( 5 ), 'code' ) );
		$this->assertArrayNotHasKey( 'refresh_token', $this->services->credentials()->get( $id ) );
	}

	public function test_delete_removes_connection_sources_and_secrets(): void {
		$id     = $this->ics_connection();
		$source = $this->source( $id );
		$this->services->credentials()->update( $id, array( 'auth_header' => 'x' ) );
		$this->assertSame( 1, $this->rest( 'GET', '/admin/connections/' . $id )->get_data()['source_count'] );
		$this->assertSame( 200, $this->rest( 'DELETE', '/admin/connections/' . $id )->get_status() );
		$this->assertNull( $this->services->connections()->find( $id ) );
		$this->assertNull( $this->services->sources()->find( $source ) );
		$this->assertSame( array(), $this->services->credentials()->get( $id ) );
		$this->assertContains( 'connection_deleted', array_column( $this->services->audit()->recent( 5 ), 'action' ) );
		$this->assertSame( 404, $this->rest( 'DELETE', '/admin/connections/' . $id )->get_status() );
	}

	public function test_oauth_service_authorization_parameters_per_provider(): void {
		$oauth  = $this->services->oauth();
		$google = $this->services->connections()->find( $this->google( array( 'client_id' => 'gid', 'attachment_access' => true ) ) );
		$req    = $oauth->authorizationRequest( $google, 'https://site.test/cb' );
		parse_str( (string) wp_parse_url( $req['url'], PHP_URL_QUERY ), $q );
		$this->assertSame( 'offline', $q['access_type'] );
		$this->assertSame( 'consent', $q['prompt'] );
		$this->assertSame( 'S256', $q['code_challenge_method'] );
		$this->assertStringContainsString( 'drive.readonly', $q['scope'] );
		$this->assertSame( \SW\Calendar\Infrastructure\OAuth\OAuthService::base64Url( hash( 'sha256', $req['verifier'], true ) ), $q['code_challenge'] );

		$ms_id = $this->services->connections()->create( Provider::Microsoft, 'M', AuthMode::OAuth, ConnectionStatus::Disconnected, array( 'client_id' => 'mid', 'tenant_id' => 'bad tenant!' ) );
		$ms    = $oauth->authorizationRequest( $this->services->connections()->find( $ms_id ), 'https://site.test/cb' );
		$this->assertStringStartsWith( 'https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?', $ms['url'] );
		$this->assertStringContainsString( 'response_mode=query', $ms['url'] );

		$this->expectException( CalendarException::class );
		$oauth->authorizationRequest( $this->services->connections()->find( $this->google( array() ) ), 'https://site.test/cb' );
	}

	public function test_oauth_service_access_token_cache_refresh_and_errors(): void {
		$oauth = $this->services->oauth();
		$ms_id = $this->services->connections()->create( Provider::Microsoft, 'M', AuthMode::OAuth, ConnectionStatus::Connected, array( 'client_id' => 'mid', 'tenant_id' => 'contoso.onmicrosoft.com' ) );
		$ms    = $this->services->connections()->find( $ms_id );
		$now   = $this->clock->now()->getTimestamp();

		$this->services->credentials()->update( $ms_id, array( 'access_token' => 'cached', 'refresh_token' => 'rt', 'expires_at' => (string) ( $now + 600 ) ) );
		$this->assertSame( 'cached', $oauth->accessToken( $ms ) );
		$this->assertSame( array(), $this->http->requests );

		$this->http->push( 200, array( 'access_token' => 'fresh', 'expires_in' => 10 ) );
		$this->assertSame( 'fresh', $oauth->accessToken( $ms, true ) );
		parse_str( (string) $this->http->requests[0]->body, $form );
		$this->assertSame( 'refresh_token', $form['grant_type'] );
		$this->assertStringContainsString( 'Calendars.Read', $form['scope'] );
		$secrets = $this->services->credentials()->get( $ms_id );
		$this->assertSame( 'rt', $secrets['refresh_token'], 'The refresh token is kept when not rotated' );
		$this->assertSame( (string) ( $now + 60 ), $secrets['expires_at'], 'Lifetime is at least one minute' );

		$this->http->push( 503, array( 'error' => 'temporarily_unavailable' ), array( 'Retry-After' => '30' ) );
		try {
			$oauth->accessToken( $ms, true );
			$this->fail( 'Expected rate limit' );
		} catch ( CalendarException $e ) {
			$this->assertSame( ErrorCode::RateLimited, $e->errorCode );
		}
		$this->http->push( 400, 'not json' );
		try {
			$oauth->accessToken( $ms, true );
			$this->fail( 'Expected auth error' );
		} catch ( CalendarException $e ) {
			$this->assertSame( ErrorCode::AuthRequired, $e->errorCode );
			$this->assertStringContainsString( 'http_400', $e->getMessage() );
		}

		$this->services->credentials()->update( $ms_id, array( 'refresh_token' => null, 'access_token' => null ) );
		$this->expectException( CalendarException::class );
		$oauth->accessToken( $ms );
	}

	public function test_oauth_service_revocation_cases(): void {
		$oauth = $this->services->oauth();
		$ms_id = $this->services->connections()->create( Provider::Microsoft, 'M', AuthMode::OAuth, ConnectionStatus::Connected, array( 'client_id' => 'mid' ) );
		$this->services->credentials()->update( $ms_id, array( 'refresh_token' => 'rt' ) );
		$this->assertFalse( $oauth->revoke( $this->services->connections()->find( $ms_id ) ), 'Microsoft has no revocation endpoint' );

		$g = $this->services->connections()->find( $this->google() );
		$this->assertFalse( $oauth->revoke( $g ), 'Nothing to revoke without tokens' );

		$this->services->credentials()->update( $g->id, array( 'access_token' => 'at' ) );
		$this->http->push( 400, '' );
		$this->assertFalse( $oauth->revoke( $g ) );
		$this->assertSame( 'token=at', $this->http->requests[0]->body );

		$this->http->queue[] = static function (): never {
			throw new CalendarException( ErrorCode::FetchFailed, 'offline' );
		};
		$this->assertFalse( $oauth->revoke( $g ) );
		$this->assertSame( array(), $this->http->queue );
	}
}
