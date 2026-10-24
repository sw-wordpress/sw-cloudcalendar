<?php
/**
 * Bootstrap for WordPress integration tests.
 */

$swc_tests_dir = __DIR__ . '/vendor/wp-phpunit/wp-phpunit';
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'SW_CALENDAR_AUTOLOADER', __DIR__ . '/vendor/autoload.php' );
define( 'SW_CALENDAR_TEST_SAVEPOINTS', true );
define( 'SW_CALENDAR_PRIVATE_STORAGE_PATH', sys_get_temp_dir() . '/swc-wp-tests-storage-' . getmypid() );

require_once $swc_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/sw-cloudcalendar.php';
	}
);

require $swc_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/tests/SWC_TestCase.php';
