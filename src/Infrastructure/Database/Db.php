<?php
/**
 * Small helpers shared by the wpdb repositories.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

final class Db {

	public static function wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function format( ?\DateTimeInterface $time ): ?string {
		return null === $time ? null : \DateTimeImmutable::createFromInterface( $time )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public static function date( mixed $value ): ?\DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * @return array<mixed>
	 */
	public static function json( mixed $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$data = json_decode( $value, true );
		return is_array( $data ) ? $data : array();
	}

	public static function encode( mixed $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Comma separated `%d` placeholders for an IN() list.
	 *
	 * @param array<mixed> $values
	 */
	public static function int_placeholders( array $values ): string {
		return implode( ',', array_fill( 0, count( $values ), '%d' ) );
	}

	/**
	 * @param array<mixed> $values
	 */
	public static function string_placeholders( array $values ): string {
		return implode( ',', array_fill( 0, count( $values ), '%s' ) );
	}
}
