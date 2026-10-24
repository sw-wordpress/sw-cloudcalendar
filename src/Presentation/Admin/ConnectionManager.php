<?php
/**
 * Connection administration incl. the OAuth flow.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Admin;

use SW\Calendar\Application\Security\UrlGuard;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Infrastructure\Provider\Ics\IcsProvider;
use SW\Calendar\Services;

final class ConnectionManager {

	public const STATE_PREFIX = 'sw_calendar_oauth_';
	private const STATE_TTL   = 600;

	/** Secret keys accepted per auth mode (write-only). */
	private const SECRET_KEYS = array(
		'oauth'       => array( 'client_secret' ),
		'api_key'     => array( 'api_key' ),
		'http_header' => array( 'auth_header' ),
		'none'        => array(),
	);

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array( Connection $connection ): array {
		$secrets = $this->services->credentials()->keys( $connection->id );
		return array(
			'id'           => $connection->id,
			'provider'     => $connection->provider->value,
			'name'         => $connection->name,
			'auth_mode'    => $connection->authMode->value,
			'status'       => $connection->status->value,
			'account_ref'  => $connection->accountRef,
			'settings'     => $connection->settings,
			// Only the presence of secrets is reported, never their values.
			'secrets_set'  => array_values( array_diff( $secrets, array( 'access_token', 'refresh_token', 'expires_at', 'scope' ) ) ),
			'authorized'   => in_array( 'refresh_token', $secrets, true ),
			'capabilities' => $this->services->providers()->get( $connection->provider )->capabilities( $connection )->toArray(),
			'source_count' => count( $this->services->sources()->ids_for_connection( $connection->id ) ),
			'redirect_uri' => self::redirect_uri(),
			'updated_at'   => $connection->updatedAt?->format( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function save( ?int $id, array $data ): Connection {
		$existing = null === $id ? null : $this->services->connections()->find( $id );
		if ( null !== $id && null === $existing ) {
			throw new ValidationException( array( 'id' => 'not_found' ), 'Connection not found.', 404 );
		}
		$errors   = array();
		$provider = $existing?->provider ?? Provider::tryFrom( (string) ( $data['provider'] ?? '' ) );
		if ( null === $provider ) {
			throw new ValidationException( array( 'provider' => 'invalid' ) );
		}
		$auth = $existing?->authMode ?? AuthMode::tryFrom( (string) ( $data['auth_mode'] ?? '' ) );
		if ( null === $auth || ! in_array( $auth, AuthMode::allowedFor( $provider ), true ) ) {
			throw new ValidationException( array( 'auth_mode' => 'invalid' ) );
		}
		$name = trim( sanitize_text_field( (string) ( $data['name'] ?? $existing?->name ?? '' ) ) );
		if ( '' === $name || mb_strlen( $name ) > 190 ) {
			$errors['name'] = 'invalid';
		}
		$settings = $this->settings( $provider, $auth, (array) ( $data['settings'] ?? array() ), $existing?->settings ?? array(), $errors );
		$secrets  = array();
		foreach ( (array) ( $data['secrets'] ?? array() ) as $key => $value ) {
			if ( ! in_array( $key, self::SECRET_KEYS[ $auth->value ], true ) ) {
				$errors[ 'secrets.' . $key ] = 'not_allowed';
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				if ( strlen( $value ) > 4096 || 1 === preg_match( '/[\r\n]/', $value ) ) {
					$errors[ 'secrets.' . $key ] = 'invalid';
					continue;
				}
				$secrets[ $key ] = $value;
			}
		}
		if ( null === $existing && AuthMode::ApiKey === $auth && ! isset( $secrets['api_key'] ) ) {
			$errors['secrets.api_key'] = 'required';
		}
		if ( null === $existing && AuthMode::HttpHeader === $auth && ! isset( $secrets['auth_header'] ) ) {
			$errors['secrets.auth_header'] = 'required';
		}
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}

		$repo = $this->services->connections();
		if ( null === $existing ) {
			$status = AuthMode::OAuth === $auth ? ConnectionStatus::Disconnected : ConnectionStatus::Connected;
			$id     = $repo->create( $provider, $name, $auth, $status, $settings );
		} else {
			$repo->update( (int) $id, $name, $settings );
			$scope_changed = ( $existing->settings['attachment_access'] ?? false ) !== ( $settings['attachment_access'] ?? false )
				|| ( $existing->settings['client_id'] ?? '' ) !== ( $settings['client_id'] ?? '' )
				|| ( $existing->settings['tenant_id'] ?? '' ) !== ( $settings['tenant_id'] ?? '' );
			if ( AuthMode::OAuth === $auth && $scope_changed ) {
				// Changed client or scopes require a new consent.
				$this->services->credentials()->update(
					(int) $id,
					array(
						'access_token'  => null,
						'refresh_token' => null,
						'expires_at'    => null,
					)
				);
				$repo->setStatus( (int) $id, ConnectionStatus::Disconnected );
			}
		}
		if ( array() !== $secrets ) {
			$this->services->credentials()->update( (int) $id, $secrets );
		}
		$this->services->audit()->record( null === $existing ? 'connection_created' : 'connection_updated', 'connection', (int) $id, array( 'provider' => $provider->value ) );
		return $repo->find( (int) $id );
	}

	/**
	 * @param array<string, mixed>  $input
	 * @param array<string, mixed>  $current
	 * @param array<string, string> $errors
	 * @return array<string, mixed>
	 */
	private function settings( Provider $provider, AuthMode $auth, array $input, array $current, array &$errors ): array {
		$settings = $current;
		$bool     = static fn ( mixed $v ): bool => in_array( $v, array( true, 1, '1', 'true', 'yes', 'on' ), true );
		switch ( $provider ) {
			case Provider::Ics:
				if ( array_key_exists( 'url', $input ) || ! isset( $settings['url'] ) ) {
					$url             = IcsProvider::normalizeUrl( esc_url_raw( (string) ( $input['url'] ?? '' ), array( 'https', 'http', 'webcal', 'webcals' ) ) );
					$settings['url'] = $url;
				}
				if ( array_key_exists( 'allow_http', $input ) ) {
					$settings['allow_http'] = $bool( $input['allow_http'] );
				}
				if ( array_key_exists( 'max_feed_mb', $input ) ) {
					$mb = filter_var( $input['max_feed_mb'], FILTER_VALIDATE_INT );
					if ( false === $mb || $mb < 1 || $mb > 50 ) {
						$errors['settings.max_feed_mb'] = 'out_of_range';
					} else {
						$settings['max_feed_mb'] = $mb;
					}
				}
				try {
					( new UrlGuard() )->check( (string) $settings['url'], (bool) ( $settings['allow_http'] ?? false ) );
				} catch ( CalendarException $e ) {
					$errors['settings.url'] = 'unsafe_url';
				}
				break;
			case Provider::Google:
			case Provider::Microsoft:
				foreach ( array( 'client_id', 'tenant_id', 'test_calendar_id' ) as $key ) {
					if ( array_key_exists( $key, $input ) ) {
						$settings[ $key ] = trim( sanitize_text_field( (string) $input[ $key ] ) );
					}
				}
				foreach ( array( 'attachment_access', 'shared_calendars' ) as $key ) {
					if ( array_key_exists( $key, $input ) ) {
						$settings[ $key ] = $bool( $input[ $key ] );
					}
				}
				if ( AuthMode::OAuth === $auth && '' === (string) ( $settings['client_id'] ?? '' ) ) {
					$errors['settings.client_id'] = 'required';
				}
				if ( Provider::Microsoft === $provider && 1 !== preg_match( '/^[A-Za-z0-9.\-]{1,100}$/', (string) ( $settings['tenant_id'] ?? '' ) ) ) {
					$errors['settings.tenant_id'] = 'invalid';
				}
				// Shared mailboxes / group calendars are out of scope for V1 (SPECIFICATIONS.md §6.2).
				if ( ! empty( $settings['shared_calendars'] ) ) {
					$errors['settings.shared_calendars'] = 'not_supported';
				}
				break;
		}
		return $settings;
	}

	/**
	 * @return array{url: string}
	 */
	public function start_authorization( int $id ): array {
		$connection = $this->require( $id );
		if ( AuthMode::OAuth !== $connection->authMode ) {
			throw new ValidationException( array( 'auth_mode' => 'not_oauth' ) );
		}
		$request = $this->services->oauth()->authorizationRequest( $connection, self::redirect_uri() );
		set_transient(
			self::STATE_PREFIX . hash( 'sha256', $request['state'] ),
			array(
				'connection_id' => $connection->id,
				'user_id'       => get_current_user_id(),
				'verifier'      => $request['verifier'],
				'created'       => time(),
			),
			self::STATE_TTL
		);
		$this->services->connections()->setStatus( $connection->id, ConnectionStatus::Connecting );
		return array( 'url' => $request['url'] );
	}

	/**
	 * Step 1 of the completion (provider redirect, no WordPress session required): the code is attached to the
	 * pending state server side. Returns the admin URL to continue in.
	 */
	public function accept_callback( string $state, string $code, string $error ): string {
		$key  = self::STATE_PREFIX . hash( 'sha256', $state );
		$data = '' === $state ? false : get_transient( $key );
		$base = admin_url( 'admin.php?page=' . AdminPage::SLUG ) . '#/connections';
		if ( ! is_array( $data ) || isset( $data['code'] ) ) {
			return add_query_arg( 'oauth', 'invalid_state', $base );
		}
		if ( '' !== $error || '' === $code ) {
			delete_transient( $key );
			$this->services->connections()->setStatus( (int) $data['connection_id'], ConnectionStatus::Failed );
			return add_query_arg( 'oauth', 'denied', $base );
		}
		$data['code'] = $code;
		set_transient( $key, $data, self::STATE_TTL );
		return $base . '?oauth_state=' . rawurlencode( $state );
	}

	/**
	 * Step 2 (authenticated admin request with REST nonce): exchanges the code. The state is single use, bound to the
	 * initiating administrator and expires after ten minutes.
	 */
	public function complete_authorization( string $state, int $current_user ): Connection {
		$key  = self::STATE_PREFIX . hash( 'sha256', $state );
		$data = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $data ) || ! isset( $data['code'] ) || (int) ( $data['user_id'] ?? 0 ) !== $current_user || $current_user <= 0 ) {
			throw new ValidationException( array( 'state' => 'invalid' ), 'Invalid or expired authorization state.', 403 );
		}
		$connection = $this->require( (int) $data['connection_id'] );
		try {
			$this->services->oauth()->exchangeCode( $connection, (string) $data['code'], (string) $data['verifier'], self::redirect_uri() );
			$connected = $connection->withStatus( ConnectionStatus::Connected );
			$result    = $this->services->providers()->get( $connection->provider )->testConnection( $connected );
			$this->services->connections()->setStatus( $connection->id, $result->ok ? ConnectionStatus::Connected : ConnectionStatus::Failed );
			$this->services->connections()->set_account_ref( $connection->id, $result->accountRef );
		} catch ( CalendarException $e ) {
			$this->services->connections()->setStatus( $connection->id, ConnectionStatus::Failed );
			$this->services->logger()->log( 'error', 'oauth_failed', $e->getMessage(), array( 'connection_id' => $connection->id ) );
			throw $e;
		}
		$this->services->audit()->record( 'connection_authorized', 'connection', $connection->id );
		return $this->require( $connection->id );
	}

	/**
	 * @return array{ok: bool, message: string, error_code: ?string}
	 */
	public function test( int $id ): array {
		$connection = $this->require( $id );
		$result     = $this->services->providers()->get( $connection->provider )->testConnection( ConnectionStatus::Connecting === $connection->status ? $connection->withStatus( ConnectionStatus::Connected ) : $connection );
		if ( $result->ok && null !== $result->accountRef ) {
			$this->services->connections()->set_account_ref( $id, $result->accountRef );
		}
		if ( $result->ok && in_array( $connection->status, array( ConnectionStatus::Failed, ConnectionStatus::ReauthRequired ), true ) && ( AuthMode::OAuth !== $connection->authMode || in_array( 'refresh_token', $this->services->credentials()->keys( $id ), true ) ) ) {
			$this->services->connections()->setStatus( $id, ConnectionStatus::Connected );
		}
		return array(
			'ok'         => $result->ok,
			'message'    => $result->message,
			'error_code' => $result->errorCode?->value,
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function calendars( int $id ): array {
		$connection = $this->require( $id );
		return array_map(
			static fn ( $definition ): array => $definition->toArray(),
			$this->services->providers()->get( $connection->provider )->listCalendars( $connection )
		);
	}

	/**
	 * Revokes tokens where supported and removes them locally. Sources keep their last data (they stay visible
	 * according to their WordPress visibility until an administrator disables them).
	 */
	public function disconnect( int $id ): void {
		$connection = $this->require( $id );
		if ( AuthMode::OAuth === $connection->authMode ) {
			try {
				$this->services->oauth()->revoke( $connection );
			} catch ( \Throwable $e ) {
				$this->services->logger()->log( 'warning', 'oauth_revoke_failed', 'Token revocation failed.', array( 'connection_id' => $id ) );
			}
			$this->services->credentials()->update(
				$id,
				array(
					'access_token'  => null,
					'refresh_token' => null,
					'expires_at'    => null,
					'scope'         => null,
				)
			);
		}
		$this->services->connections()->setStatus( $id, ConnectionStatus::Disconnected );
		$this->services->audit()->record( 'connection_disconnected', 'connection', $id );
	}

	/** Deletes the connection, its secrets and all its sources with their data. */
	public function delete( int $id ): void {
		$connection = $this->require( $id );
		$this->disconnect( $id );
		$sources = new SourceManager( $this->services );
		foreach ( $this->services->sources()->ids_for_connection( $id ) as $source_id ) {
			$sources->delete( $source_id );
		}
		$this->services->credentials()->delete( $id );
		$this->services->connections()->delete( $id );
		$this->services->audit()->record( 'connection_deleted', 'connection', $id, array( 'provider' => $connection->provider->value ) );
	}

	public static function redirect_uri(): string {
		return rest_url( 'sw-cloudcalendar/v1/oauth/callback' );
	}

	private function require( int $id ): Connection {
		$connection = $this->services->connections()->find( $id );
		if ( null === $connection ) {
			throw new ValidationException( array( 'id' => 'not_found' ), 'Connection not found.', 404 );
		}
		return $connection;
	}
}
