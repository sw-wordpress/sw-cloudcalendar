<?php
/**
 * URLs of plugin controlled endpoints. Attachment URLs contain only the numeric internal id, never storage paths or
 * tokens (SPECIFICATIONS.md §3.6, §4 "no secret download tokens in public pages").
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

use SW\Calendar\Application\Port\UrlGeneratorInterface;

final class WpUrlGenerator implements UrlGeneratorInterface {

	public const EVENT_PARAM = 'swc_event';
	public const VIEW_PARAM  = 'swc_view';
	public const MONTH_PARAM = 'swc_month';
	public const PAGE_PARAM  = 'swc_page';

	private ?string $base_url = null;

	/**
	 * The page the calendar is rendered on (detail and month links stay on it). Defaults to the current request URL.
	 */
	public function set_base_url( ?string $url ): void {
		$this->base_url = $url;
	}

	public function attachmentContent( int $attachmentId ): string {
		return rest_url( 'sw-cloudcalendar/v1/attachments/' . $attachmentId . '/content' );
	}

	public function attachmentPreview( int $attachmentId ): string {
		return rest_url( 'sw-cloudcalendar/v1/attachments/' . $attachmentId . '/preview' );
	}

	public function eventDetail( int $viewId, int $occurrenceId ): string {
		return add_query_arg(
			array(
				self::VIEW_PARAM  => $viewId,
				self::EVENT_PARAM => $occurrenceId,
			),
			$this->base()
		);
	}

	public function month( int $viewId, string $month ): string {
		return add_query_arg(
			array(
				self::VIEW_PARAM  => $viewId,
				self::MONTH_PARAM => $month,
			),
			remove_query_arg( self::EVENT_PARAM, $this->base() )
		);
	}

	public function page( int $viewId, int $page ): string {
		return add_query_arg(
			array(
				self::VIEW_PARAM => $viewId,
				self::PAGE_PARAM => $page,
			),
			remove_query_arg( array( self::EVENT_PARAM, self::MONTH_PARAM ), $this->base() )
		);
	}

	private function base(): string {
		if ( null !== $this->base_url ) {
			return $this->base_url;
		}
		if ( wp_is_serving_rest_request() || wp_doing_ajax() ) {
			// REST rendering (dynamic views, month navigation) gets the page URL from the client; fall back to home.
			return home_url( '/' );
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return remove_query_arg( array( self::EVENT_PARAM, self::MONTH_PARAM, self::VIEW_PARAM, self::PAGE_PARAM ), home_url( $uri ) );
	}
}
