<?php
/**
 * Transient based cache with a global generation counter: clearing increments the counter, which invalidates every
 * key at once even with persistent object caches that cannot be enumerated.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

use SW\Calendar\Application\Port\CacheInterface;

final class TransientCache implements CacheInterface {

	public const GENERATION_OPTION = 'sw_calendar_cache_generation';
	public const STATS_OPTION      = 'sw_calendar_cache_stats';

	private ?int $generation = null;
	/** @var array<string, int> */
	private array $stats = array(
		'hits'   => 0,
		'misses' => 0,
	);

	public function __construct( private readonly string $prefix = 'sw_calendar' ) {
		add_action( 'shutdown', array( $this, 'flush_stats' ) );
	}

	public function get( string $key ): ?string {
		$value = get_transient( $this->name( $key ) );
		if ( is_string( $value ) ) {
			++$this->stats['hits'];
			return $value;
		}
		++$this->stats['misses'];
		return null;
	}

	public function set( string $key, string $value, int $ttlSeconds ): void {
		set_transient( $this->name( $key ), $value, max( 1, $ttlSeconds ) );
	}

	public function delete( string $key ): void {
		delete_transient( $this->name( $key ) );
	}

	/** Invalidates everything of this cache (template ASTs and rendered HTML). */
	public function clear(): void {
		$this->generation = $this->generation() + 1;
		update_option( self::GENERATION_OPTION, $this->generation, true );
	}

	public function generation(): int {
		if ( null === $this->generation ) {
			$this->generation = (int) get_option( self::GENERATION_OPTION, 1 );
		}
		return $this->generation;
	}

	/** Aggregated hit/miss counters for diagnostics (best effort, not exact under concurrency). */
	public function flush_stats(): void {
		if ( 0 === $this->stats['hits'] + $this->stats['misses'] ) {
			return;
		}
		$stored = get_option( self::STATS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$this->stats_merge( $stored );
		$this->stats = array(
			'hits'   => 0,
			'misses' => 0,
		);
	}

	/**
	 * @param array<string, mixed> $stored
	 */
	private function stats_merge( array $stored ): void {
		update_option(
			self::STATS_OPTION,
			array(
				'hits'   => (int) ( $stored['hits'] ?? 0 ) + $this->stats['hits'],
				'misses' => (int) ( $stored['misses'] ?? 0 ) + $this->stats['misses'],
				'since'  => (string) ( $stored['since'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			),
			false
		);
	}

	private function name( string $key ): string {
		// Transient names are limited to 172 characters; keys are hashed.
		return $this->prefix . '_' . $this->generation() . '_' . substr( hash( 'sha256', $key ), 0, 40 );
	}
}
