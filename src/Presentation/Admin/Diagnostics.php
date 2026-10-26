<?php
/**
 * Diagnostics (SPECIFICATIONS.md §14.6). Contains states, counts and error codes only: no tokens, no private event
 * contents, no storage paths of individual files. Exportable as JSON.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Admin;

use SW\Calendar\Infrastructure\Database\Schema;
use SW\Calendar\Infrastructure\WordPress\QueueRunner;
use SW\Calendar\Infrastructure\WordPress\Settings;
use SW\Calendar\Infrastructure\WordPress\StorageGuard;
use SW\Calendar\Infrastructure\WordPress\TransientCache;
use SW\Calendar\Services;

final class Diagnostics {

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function collect(): array {
		$guard   = $this->services->storage_guard();
		$storage = $guard->state();
		$stats   = get_option( TransientCache::STATS_OPTION, array() );
		$sources = array();
		foreach ( $this->services->sources()->all() as $source ) {
			$state     = $this->services->sync_state()->load( $source->id );
			$runs      = $this->services->sync_runs()->recent( $source->id, 1 );
			$sources[] = array(
				'id'              => $source->id,
				'name'            => $source->name,
				'status'          => $source->status->value,
				'visibility'      => $source->visibility->value,
				'last_success_at' => $source->lastSuccessAt?->format( 'Y-m-d\TH:i:s\Z' ),
				'next_attempt_at' => $state->nextAttemptAt?->format( 'Y-m-d\TH:i:s\Z' ),
				'last_error_code' => $state->lastErrorCode,
				'failures'        => $state->failures,
				'cursor'          => $state->cursor->isEmpty() ? 'none' : 'stored',
				'occurrences'     => $this->services->occurrences()->count_for_source( $source->id ),
				'last_run'        => $runs[0] ?? null,
				'attachments'     => $this->services->attachments()->stats( $source->id ),
			);
		}
		$connections = array();
		foreach ( $this->services->connections()->all() as $connection ) {
			$connections[] = array(
				'id'        => $connection->id,
				'name'      => $connection->name,
				'provider'  => $connection->provider->value,
				'auth_mode' => $connection->authMode->value,
				'status'    => $connection->status->value,
			);
		}
		$vault = $this->services->vault();
		return array(
			'generated_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'versions'      => array(
				'plugin'    => SW_CALENDAR_VERSION,
				'db_schema' => (int) get_option( Schema::VERSION_OPTION, 0 ),
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
			),
			'environment'   => array(
				'sodium'         => function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ),
				'fileinfo'       => function_exists( 'finfo_open' ),
				'gd'             => extension_loaded( 'gd' ),
				'imagick'        => extension_loaded( 'imagick' ),
				'intl'           => extension_loaded( 'intl' ),
				'curl'           => function_exists( 'curl_init' ),
				'missing_tables' => Schema::missing_tables(),
			),
			'security'      => array(
				'encryption_key'     => $vault->usesFallback ? 'wp_salts_fallback' : 'dedicated_key',
				'capability_holders' => $this->capability_roles(),
			),
			'storage'       => array(
				'configured' => defined( 'SW_CALENDAR_PRIVATE_STORAGE_PATH' ) ? 'constant' : 'uploads_fallback',
				'safe'       => (bool) ( $storage['safe'] ?? false ),
				'method'     => (string) ( $storage['method'] ?? '' ),
				'reason'     => (string) ( $storage['reason'] ?? '' ),
				'checked_at' => isset( $storage['checked_at'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $storage['checked_at'] ) : null,
				'bytes'      => $this->services->attachments()->storedBytes(),
				'quota_mb'   => (int) Settings::get( 'global_quota_mb' ),
			),
			'cron'          => array(
				'mode'             => Settings::get( 'cron_mode' ),
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'next_tick'        => $this->next_event( QueueRunner::HOOK_TICK ),
				'next_daily'       => $this->next_event( QueueRunner::HOOK_DAILY ),
				'last_tick'        => $this->time_option( QueueRunner::LAST_TICK_OPTION ),
				'last_maintenance' => $this->time_option( QueueRunner::LAST_MAINTENANCE_OPTION ),
				'jobs'             => (object) $this->services->jobs()->counts(),
			),
			'cache'         => array(
				'html_cache_enabled'      => (bool) Settings::get( 'html_cache' ),
				'generation'              => $this->services->cache()->generation(),
				'hits'                    => (int) ( $stats['hits'] ?? 0 ),
				'misses'                  => (int) ( $stats['misses'] ?? 0 ),
				'since'                   => $stats['since'] ?? null,
				'persistent_object_cache' => wp_using_ext_object_cache(),
			),
			'connections'   => $connections,
			'sources'       => $sources,
			'recent_errors' => $this->services->logger()->recent( 20, 0, 'error' ),
		);
	}

	/**
	 * @return list<string>
	 */
	private function capability_roles(): array {
		$roles = array();
		foreach ( wp_roles()->role_objects as $name => $role ) {
			if ( $role->has_cap( \SW\Calendar\Infrastructure\WordPress\Capabilities::MANAGE ) ) {
				$roles[] = (string) $name;
			}
		}
		return $roles;
	}

	private function next_event( string $hook ): ?string {
		$time = wp_next_scheduled( $hook );
		return false === $time ? null : gmdate( 'Y-m-d\TH:i:s\Z', (int) $time );
	}

	private function time_option( string $option ): ?string {
		$time = (int) get_option( $option, 0 );
		return $time > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : null;
	}
}
