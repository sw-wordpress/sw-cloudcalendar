<?php
/**
 * Sanitizes provider descriptions to a small formatting allowlist.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\WordPress;

use SW\Calendar\Application\Port\HtmlSanitizerInterface;

final class KsesSanitizer implements HtmlSanitizerInterface {

	/**
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed(): array {
		$plain = array();
		$link  = array(
			'href'  => true,
			'title' => true,
		);
		return array(
			'p'          => $plain,
			'br'         => $plain,
			'strong'     => $plain,
			'b'          => $plain,
			'em'         => $plain,
			'i'          => $plain,
			'u'          => $plain,
			's'          => $plain,
			'ul'         => $plain,
			'ol'         => $plain,
			'li'         => $plain,
			'a'          => $link,
			'blockquote' => $plain,
			'h3'         => $plain,
			'h4'         => $plain,
			'span'       => $plain,
			'div'        => $plain,
		);
	}

	public function sanitizeDescription( string $html ): string {
		// Images and other remote resources are removed: only local, plugin controlled URLs may load files.
		$clean = wp_kses( $html, self::allowed(), array( 'http', 'https', 'mailto', 'tel' ) );
		// External links leave the site in a new context without referrer/opener.
		return (string) preg_replace( '/<a\s/i', '<a rel="nofollow noopener noreferrer" target="_blank" ', $clean );
	}
}
