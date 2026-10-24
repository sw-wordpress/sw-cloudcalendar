<?php
/**
 * Gutenberg block `sw-cloudcalendar/calendar`, rendered dynamically on the server.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Block;

use SW\Calendar\Presentation\Frontend\CalendarOutput;
use SW\Calendar\Services;

final class CalendarBlock {

	public function register(): void {
		register_block_type(
			SW_CALENDAR_DIR . 'blocks/calendar',
			array( 'render_callback' => array( $this, 'render' ) )
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'sw-cloudcalendar-calendar-editor-script', 'sw-cloudcalendar', SW_CALENDAR_DIR . 'languages' );
		}
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render( array $attributes ): string {
		$view = (int) ( $attributes['viewId'] ?? 0 );
		unset( $attributes['viewId'], $attributes['className'], $attributes['align'], $attributes['lock'], $attributes['metadata'], $attributes['style'] );
		$html = ( new CalendarOutput( Services::instance() ) )->render( array( 'id' => $view ) + $attributes );
		if ( '' === $html ) {
			return '';
		}
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'swc-block' ) ) . '>' . $html . '</div>';
	}
}
