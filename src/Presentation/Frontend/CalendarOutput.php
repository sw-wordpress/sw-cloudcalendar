<?php
/**
 * Shared output path of shortcode and block (SPECIFICATIONS.md §10.3: no parallel implementation).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Frontend;

use SW\Calendar\Application\Render\FilterSchema;
use SW\Calendar\Application\Render\RenderResult;
use SW\Calendar\Infrastructure\WordPress\WpUrlGenerator;
use SW\Calendar\Services;

/**
 * Views that contain non-public sources are never serialized into page HTML: a placeholder is printed and the
 * calendar is loaded through the REST endpoint (private, no-store) after a fresh nonce was fetched, so a full page
 * cache cannot hand protected events to guests (SPECIFICATIONS.md §9.3). Public views are rendered inline.
 */
final class CalendarOutput {

	public const SCRIPT = 'sw-cloudcalendar-frontend';
	public const STYLE  = 'sw-cloudcalendar-frontend';

	/** @var array<string, true> CSS scopes already printed in this request. */
	private static array $printed_css = array();

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @param array<string, mixed> $attributes Shortcode/block attributes (validated by the filter schema).
	 */
	public function render( array $attributes ): string {
		$view_id = isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0;
		if ( $view_id <= 0 ) {
			return '';
		}
		unset( $attributes['id'] );
		$filters = $this->normalize_attributes( $attributes );
		$this->enqueue();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation parameters, validated below.
		$targeted = isset( $_GET[ WpUrlGenerator::VIEW_PARAM ] ) && absint( $_GET[ WpUrlGenerator::VIEW_PARAM ] ) === $view_id;
		$event_id = $targeted && isset( $_GET[ WpUrlGenerator::EVENT_PARAM ] ) ? absint( $_GET[ WpUrlGenerator::EVENT_PARAM ] ) : 0;
		$month    = $targeted && isset( $_GET[ WpUrlGenerator::MONTH_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ WpUrlGenerator::MONTH_PARAM ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' !== $month && 1 === preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
			$filters['month'] = $month;
		}

		return ViewLocale::run(
			$this->services,
			ViewLocale::for_view( $this->services, $view_id ),
			fn (): string => $this->render_view( $view_id, $filters, $event_id )
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private function render_view( int $view_id, array $filters, int $event_id ): string {
		$renderer = $this->services->renderer();
		if ( $renderer->requiresDynamicLoading( $view_id ) && ! $this->is_editor_preview() ) {
			return $this->placeholder( $view_id, $filters, $event_id );
		}
		$user = get_current_user_id() > 0 ? get_current_user_id() : null;
		if ( $event_id > 0 ) {
			$detail = $renderer->renderDetail( $view_id, $event_id, $user );
			if ( RenderResult::OK === $detail->status ) {
				return $this->css( $detail->css ) . $this->back_link( $view_id ) . $detail->html;
			}
		}
		$result = $renderer->render( $view_id, $filters, $user );
		if ( RenderResult::OK !== $result->status ) {
			return '';
		}
		if ( array() !== $result->errors && current_user_can( 'edit_posts' ) ) {
			$this->services->logger()->log( 'debug', 'shortcode_filter_ignored', 'Invalid calendar parameters were ignored.', array( 'view_id' => $view_id ) );
		}
		return $this->css( $result->css ) . $result->html;
	}

	/**
	 * @param array<string, mixed> $attributes
	 * @return array<string, mixed>
	 */
	private function normalize_attributes( array $attributes ): array {
		$filters = array();
		$map     = array(
			'showImages'      => 'show_images',
			'showAttachments' => 'show_attachments',
			'displayMode'     => 'view',
		);
		foreach ( $attributes as $key => $value ) {
			$key = $map[ $key ] ?? strtolower( (string) $key );
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			if ( is_array( $value ) ) {
				$value = implode( ',', array_map( 'strval', $value ) );
			}
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$filters[ $key ] = (string) $value;
			}
		}
		return $filters;
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private function placeholder( int $view_id, array $filters, int $event_id ): string {
		$labels = $this->services->labels();
		// The REST endpoint validates strictly; invalid shortcode values are dropped here like in inline rendering.
		$parsed  = ( new FilterSchema() )->normalize( $filters );
		$filters = array_diff_key( $filters, $parsed->errors, array_flip( $parsed->unknown ) );
		return sprintf(
			'<div class="swc-calendar swc-dynamic" data-swc-view="%1$d" data-swc-filters="%2$s" data-swc-event="%3$d" aria-busy="true"><p class="swc-loading">%4$s</p><noscript><p>%5$s</p></noscript></div>',
			$view_id,
			esc_attr( (string) wp_json_encode( (object) $filters ) ),
			$event_id,
			esc_html( $labels->loading ),
			esc_html( $labels->requiresJavascript )
		);
	}

	private function back_link( int $view_id ): string {
		$url = remove_query_arg( array( WpUrlGenerator::EVENT_PARAM, WpUrlGenerator::VIEW_PARAM ) );
		return '<p class="swc-back"><a href="' . esc_url( $url ) . '" data-swc-back="' . esc_attr( (string) $view_id ) . '">&larr; ' . esc_html__( 'Back to the calendar', 'sw-cloudcalendar' ) . '</a></p>';
	}

	/**
	 * Prints scoped template CSS once per request. The CSS was sanitized (no "<", no url(), no @import).
	 *
	 * @param array<string, string> $css
	 */
	private function css( array $css ): string {
		$out = '';
		foreach ( $css as $scope => $style ) {
			if ( isset( self::$printed_css[ $scope ] ) || '' === trim( $style ) ) {
				continue;
			}
			self::$printed_css[ $scope ] = true;
			$out                        .= '<style id="' . esc_attr( $scope ) . '-css">' . str_replace( '<', '', $style ) . '</style>';
		}
		return $out;
	}

	/** The block editor preview runs as an authenticated, non-cached REST request: render inline there. */
	private function is_editor_preview(): bool {
		return wp_is_serving_rest_request() && current_user_can( 'edit_posts' );
	}

	public function enqueue(): void {
		wp_enqueue_style( self::STYLE );
		wp_enqueue_script( self::SCRIPT );
	}

	public static function register_assets(): void {
		wp_register_style( self::STYLE, SW_CALENDAR_URL . 'assets/css/frontend.css', array(), SW_CALENDAR_VERSION );
		wp_register_script(
			self::SCRIPT,
			SW_CALENDAR_URL . 'assets/js/frontend.js',
			array(),
			SW_CALENDAR_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			self::SCRIPT,
			'window.swCloudCalendar = ' . wp_json_encode(
				array(
					'restRoot' => esc_url_raw( rest_url( 'sw-cloudcalendar/v1/' ) ),
					'ajaxUrl'  => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
					'i18n'     => array(
						'close'   => __( 'Close', 'sw-cloudcalendar' ),
						'loading' => __( 'Loading calendar…', 'sw-cloudcalendar' ),
						'error'   => __( 'The calendar could not be loaded.', 'sw-cloudcalendar' ),
						'details' => __( 'Event details', 'sw-cloudcalendar' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/** Admin-ajax endpoint: fresh REST nonce for logged-in visitors of (possibly cached) pages. Never cached. */
	public static function ajax_nonce(): void {
		nocache_headers();
		wp_send_json( array( 'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '' ) );
	}
}
