<?php
/**
 * Localized date formatting through wp_date().
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

use SW\Calendar\Application\Port\DateFormatterInterface;

final class WpDateFormatter implements DateFormatterInterface {

	public function format( \DateTimeInterface $time, \DateTimeZone $tz, string $format ): string {
		$result = wp_date( $format, $time->getTimestamp(), $tz );
		return is_string( $result ) ? $result : '';
	}

	public function defaultDateFormat(): string {
		return (string) get_option( 'date_format', 'j. F Y' );
	}

	public function defaultTimeFormat(): string {
		return (string) get_option( 'time_format', 'H:i' );
	}
}
