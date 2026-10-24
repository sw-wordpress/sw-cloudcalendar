<?php
/**
 * Template validation failure with editor diagnostics (line/column).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Admin;

final class TemplateSaveException extends \RuntimeException {

	/**
	 * @param array<string, string> $errors
	 * @param array<string, mixed>  $validation
	 */
	public function __construct( public readonly array $errors, public readonly array $validation ) {
		parent::__construct( 'The template is invalid.' );
	}
}
