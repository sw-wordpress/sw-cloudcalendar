<?php
/**
 * Field validation failure of an admin operation.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Admin;

final class ValidationException extends \RuntimeException {

	/**
	 * @param array<string, string> $errors Field => machine readable error code.
	 */
	public function __construct( public readonly array $errors, string $message = 'Validation failed.', public readonly int $status = 400 ) {
		parent::__construct( $message );
	}
}
