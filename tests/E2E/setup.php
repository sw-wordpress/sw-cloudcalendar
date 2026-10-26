<?php
// Disposable E2E instance: install WordPress, activate the plugin, seed data through the real services.
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8089';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'SWC_E2E_WP' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_blog_installed() ) {
	wp_install( 'SWC E2E', 'admin', 'admin@example.org', true, '', 'e2e-admin-pass' );
}
update_option( 'siteurl', 'http://127.0.0.1:8089' );
update_option( 'home', 'http://127.0.0.1:8089' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'timezone_string', 'Europe/Berlin' );
update_option( 'WPLANG', '' );
wp_create_user( 'editor1', 'e2e-editor-pass', 'editor@example.org' );
( new WP_User( get_user_by( 'login', 'editor1' )->ID ) )->set_role( 'editor' );
$result = activate_plugin( 'sw-cloudcalendar/sw-cloudcalendar.php' );
if ( is_wp_error( $result ) ) { fwrite( STDERR, $result->get_error_message() ); exit( 1 ); }
echo "installed\n";
