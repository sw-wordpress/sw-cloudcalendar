<?php
/**
 * [sw_calendar id="12" …] (SPECIFICATIONS.md §10.1).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Shortcode;

use SW\Calendar\Presentation\Frontend\CalendarOutput;
use SW\Calendar\Services;

final class CalendarShortcode {

	public const TAG = 'sw_calendar';

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function render( $atts ): string {
		return ( new CalendarOutput( Services::instance() ) )->render( is_array( $atts ) ? $atts : array() );
	}
}
