<?php
/**
 * Applies the optional language of a view (`language`, e.g. `de_DE`) while it is rendered: month and weekday names,
 * plugin labels and number formats follow it. An empty value uses the site/user locale.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Frontend;

use SW\Calendar\Services;

final class ViewLocale {

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public static function run( Services $services, string $language, callable $callback ): mixed {
		if ( '' === $language || determine_locale() === $language || ! switch_to_locale( $language ) ) {
			return $callback();
		}
		$services->forget_locale_dependent();
		try {
			return $callback();
		} finally {
			restore_previous_locale();
			$services->forget_locale_dependent();
		}
	}

	public static function for_view( Services $services, int $view_id ): string {
		return $services->views()->find( $view_id )?->language ?? '';
	}
}
