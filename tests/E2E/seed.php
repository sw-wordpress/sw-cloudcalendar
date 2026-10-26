<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8089';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'SWC_E2E_WP' ) . '/wp-load.php';

use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Domain\Source\SourceStatus;
use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Services;
use SW\Calendar\Tests\Support\FakeHttpClient;

$services = Services::instance();
$http     = new FakeHttpClient();
$services->set( 'http', $http );
$feed = file_get_contents( WP_PLUGIN_DIR . '/sw-cloudcalendar/tests/Fixtures/ics/parish.ics' );
// Shift the fixture so that events fall into "now".
$feed = str_replace( array( '202610', '202611', '202612' ), array( gmdate( 'Ym' ), gmdate( 'Ym', strtotime( '+1 month' ) ), gmdate( 'Ym', strtotime( '+2 months' ) ) ), $feed );

$connection = $services->connections()->create( Provider::Ics, 'Gemeinde-Feed', AuthMode::None, ConnectionStatus::Connected, array( 'url' => 'https://calendar.example.org/parish.ics' ) );
$sources    = $services->sources();
$public     = $sources->create( $connection, 'feed', 'Gemeindekalender', '#2271b1', 'Europe/Berlin', Visibility::Public, array(), new SourceSettings() );
$sources->update( $public, 'Gemeindekalender', '#2271b1', 'Europe/Berlin', Visibility::Public, array(), new SourceSettings(), SourceStatus::Active );
$restricted = $sources->create( $connection, 'feed-intern', 'Mitarbeiter', '#d63638', 'Europe/Berlin', Visibility::Roles, array( 'editor', 'administrator' ), new SourceSettings() );
$sources->update( $restricted, 'Mitarbeiter', '#d63638', 'Europe/Berlin', Visibility::Roles, array( 'editor', 'administrator' ), new SourceSettings(), SourceStatus::Active );
foreach ( array( $public, $restricted ) as $id ) {
	$http->push( 200, $feed );
	$report = $services->sync()->sync( $id );
	echo "sync $id: {$report->status} +{$report->metrics->created}\n";
}
$views = $services->views();
$list  = $views->save( null, 'Termine', true, array( 'source_ids' => array( $public ), 'display_mode' => 'list', 'allowed_modes' => array( 'list', 'cards', 'month' ), 'html_cache' => true ) );
$month = $views->save( null, 'Monat', true, array( 'source_ids' => array( $public ), 'display_mode' => 'month', 'html_cache' => true ) );
$mixed = $views->save( null, 'Intern', true, array( 'source_ids' => array( $public, $restricted ), 'display_mode' => 'list', 'html_cache' => true ) );
foreach ( array( 'liste' => "[sw_calendar id=\"$list\" limit=\"8\"]", 'karten' => "[sw_calendar id=\"$list\" view=\"cards\" limit=\"6\"]", 'monat' => "[sw_calendar id=\"$month\"]", 'intern' => "[sw_calendar id=\"$mixed\" limit=\"20\"]", 'block' => "<!-- wp:sw-cloudcalendar/calendar {\"viewId\":$list,\"limit\":3} /-->" ) as $slug => $content ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => ucfirst( $slug ), 'post_name' => $slug, 'post_content' => $content ) );
}
flush_rewrite_rules();
echo "views $list $month $mixed\n";
