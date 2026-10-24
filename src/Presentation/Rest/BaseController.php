<?php
/**
 * Shared helpers of the REST controllers.
 *
 * Every route has an explicit permission_callback. Admin routes require `manage_sw_calendar`; cookie authenticated
 * requests are CSRF protected by the WordPress REST nonce. Public routes still apply the source ACL internally.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Rest;

use SW\Calendar\Application\Render\Template\TemplateValidationException;
use SW\Calendar\Domain\CalendarException;
use SW\Calendar\Domain\ErrorCode;
use SW\Calendar\Infrastructure\WordPress\Capabilities;
use SW\Calendar\Infrastructure\WordPress\Settings;
use SW\Calendar\Presentation\Admin\TemplateSaveException;
use SW\Calendar\Presentation\Admin\ValidationException;
use SW\Calendar\Services;

abstract class BaseController {

	public const NAMESPACE = 'sw-cloudcalendar/v1';

	public function __construct( protected readonly Services $services ) {
	}

	abstract public function register_routes(): void;

	public function can_manage(): bool {
		return Capabilities::current_user_can_manage();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function id_arg(): array {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function body( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : array();
	}

	/**
	 * @param array<string, string> $errors
	 */
	protected function error( string $code, string $message, int $status = 400, array $errors = array() ): \WP_Error {
		$data = array( 'status' => $status );
		if ( array() !== $errors ) {
			$data['params'] = $errors;
		}
		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Maps exceptions to REST errors without leaking internals.
	 */
	protected function from_exception( \Throwable $e ): \WP_Error {
		if ( $e instanceof TemplateSaveException ) {
			$error = $this->error( 'swc_invalid_template', __( 'The template is invalid.', 'sw-cloudcalendar' ), 400, $e->errors );
			$error->add_data( array_merge( (array) $error->get_error_data(), array( 'validation' => $e->validation ) ) );
			return $error;
		}
		if ( $e instanceof ValidationException ) {
			$code = match ( $e->status ) {
				404 => 'swc_not_found',
				403 => 'swc_forbidden',
				409 => 'swc_conflict',
				default => 'swc_invalid',
			};
			return $this->error( $code, $this->validation_message( $e ), $e->status, $e->errors );
		}
		if ( $e instanceof TemplateValidationException ) {
			return $this->error( 'swc_invalid_template', $e->getMessage(), 400 );
		}
		if ( $e instanceof CalendarException ) {
			$status = match ( $e->errorCode ) {
				ErrorCode::NotFound => 404,
				ErrorCode::AccessDenied => 403,
				ErrorCode::RateLimited => 429,
				ErrorCode::Locked => 409,
				ErrorCode::AuthRequired, ErrorCode::TokenExpired, ErrorCode::FetchFailed, ErrorCode::ProviderUnsupported => 502,
				default => 400,
			};
			return $this->error( 'swc_' . strtolower( $e->errorCode->value ), ErrorMessages::for( $e->errorCode, $e->getMessage() ), $status );
		}
		$this->services->logger()->log( 'error', 'rest_exception', 'Unexpected REST error.', array( 'exception' => get_class( $e ) ) );
		return $this->error( 'swc_error', __( 'An unexpected error occurred.', 'sw-cloudcalendar' ), 500 );
	}

	private function validation_message( ValidationException $e ): string {
		return match ( $e->status ) {
			404 => __( 'The requested item was not found.', 'sw-cloudcalendar' ),
			403 => __( 'The request was not permitted.', 'sw-cloudcalendar' ),
			409 => __( 'The item is in use or in conflict with another item.', 'sw-cloudcalendar' ),
			default => __( 'Please check the highlighted fields.', 'sw-cloudcalendar' ),
		};
	}

	/**
	 * @param list<mixed> $items
	 */
	protected function collection( array $items, int $total, int $page, int $per_page ): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array(
				'items' => $items,
				'total' => $total,
				'pages' => (int) max( 1, ceil( $total / max( 1, $per_page ) ) ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function pagination_args(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Simple fixed-window rate limit per client IP for public endpoints (0 disables it).
	 */
	protected function rate_limited(): bool {
		$limit = (int) Settings::get( 'public_rate_limit' );
		if ( $limit <= 0 || current_user_can( Capabilities::MANAGE ) ) {
			return false;
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'sw_calendar_rl_' . substr( hash( 'sha256', $ip . '|' . gmdate( 'YmdHi' ) ), 0, 32 );
		$hit = (int) get_transient( $key ) + 1;
		set_transient( $key, $hit, 2 * MINUTE_IN_SECONDS );
		return $hit > $limit;
	}

	protected function too_many(): \WP_Error {
		return $this->error( 'swc_rate_limited', __( 'Too many requests. Please try again shortly.', 'sw-cloudcalendar' ), 429 );
	}

	/**
	 * Cache headers for calendar data: public results may be cached briefly, everything else never.
	 */
	protected function cache_headers( \WP_REST_Response $response, bool $public ): \WP_REST_Response {
		if ( $public ) {
			$response->header( 'Cache-Control', 'public, max-age=60' );
		} else {
			$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
			$response->header( 'Vary', 'Cookie' );
		}
		return $response;
	}

	protected function current_user(): ?int {
		$id = get_current_user_id();
		return $id > 0 ? $id : null;
	}
}
