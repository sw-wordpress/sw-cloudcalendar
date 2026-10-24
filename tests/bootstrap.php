<?php

/**
 * Smoke-test a checkout or staged release without WordPress, network or database access.
 * Usage: php tests/bootstrap.php [plugin-directory]
 */

declare(strict_types=1);

define('ABSPATH', '/test/wordpress/');

function plugin_dir_path(string $file): string
{
    return dirname($file) . '/';
}

function plugin_dir_url(string $file): string
{
    return 'https://example.test/wp-content/plugins/sw-cloudcalendar/';
}

function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['test_actions'][$hook][] = $callback;
}

function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['test_filters'][$hook][] = $callback;
}

function register_activation_hook(string $file, mixed $callback): void
{
    $GLOBALS['test_activation'] = $callback;
}

function register_deactivation_hook(string $file, mixed $callback): void
{
    $GLOBALS['test_deactivation'] = $callback;
}

function is_admin(): bool
{
    return true;
}

$root = $argv[1] ?? dirname(__DIR__);
require $root . '/sw-cloudcalendar.php';

foreach (['test_activation', 'test_deactivation'] as $callback) {
    if (!is_callable($GLOBALS[$callback] ?? null)) {
        throw new RuntimeException('Lifecycle callback could not be loaded: ' . $callback);
    }
}
foreach (['admin_menu', 'rest_api_init', 'init', 'plugins_loaded', 'wp_ajax_nopriv_sw_calendar_rest_nonce'] as $hook) {
    if (!isset($GLOBALS['test_actions'][$hook])) {
        throw new RuntimeException('Integration was not registered: ' . $hook);
    }
}
foreach (
    [
    \SW\Calendar\Application\Render\ViewRenderer::class,
    \SW\Calendar\Application\Sync\SyncService::class,
    \SW\Calendar\Infrastructure\Provider\Ics\IcsProvider::class,
    \SW\Calendar\Presentation\Rest\PublicController::class,
    \SW\Calendar\Presentation\Cli\CalendarCommand::class,
    ] as $class
) {
    if (!class_exists($class)) {
        throw new RuntimeException('Plugin class could not be loaded: ' . $class);
    }
}
if (isset($GLOBALS['test_actions']['admin_notices'])) {
    throw new RuntimeException('Plugin reported a bootstrap error.');
}

// Build names dynamically: this same external test checks original and isolated libraries.
$prefix = is_file($root . '/vendor-prefixed/autoload.php') ? 'SW\\Calendar\\Dependencies\\' : '';
$readerClass = $prefix . 'Sabre\\VObject\\Reader';
$calendar = $readerClass::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART;TZID=Europe/Berlin:20261025T100000\r\nRRULE:FREQ=WEEKLY;COUNT=2\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
if (count($calendar->select('VEVENT')) !== 1) {
    throw new RuntimeException('sabre/vobject parsing failed.');
}
// End-to-end through the plugin's own parser and expander (exercises the rewritten library references).
$parsed = (new \SW\Calendar\Infrastructure\Provider\Ics\IcsParser())->parse(
    (string) file_get_contents(__DIR__ . '/Fixtures/ics/parish.ics') ?: "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
    new DateTimeZone('UTC')
);
$builder = new \SW\Calendar\Application\Sync\OccurrenceBuilder(
    new \SW\Calendar\Application\Sync\RecurrenceExpander(),
    new class implements \SW\Calendar\Application\Port\HtmlSanitizerInterface {
        public function sanitizeDescription(string $html): string
        {
            return strip_tags($html);
        }
    }
);
$occurrences = $builder->build(1, $parsed['items'], new \SW\Calendar\Domain\Provider\SyncWindow(new DateTimeImmutable('2026-10-01T00:00:00Z'), new DateTimeImmutable('2026-12-01T00:00:00Z')), new \SW\Calendar\Domain\Source\SourceSettings());
if (count($occurrences) < 3) {
    throw new RuntimeException('Recurrence expansion through the isolated library failed.');
}
$tz = $prefix . 'Sabre\\VObject\\TimeZoneUtil';
if ($tz::getTimeZone('W. Europe Standard Time', null, true)->getName() !== 'Europe/Berlin') {
    throw new RuntimeException('Windows time zone mapping resources are missing.');
}
if ($prefix !== '') {
    foreach (['Sabre\\VObject\\Reader', 'Sabre\\Xml\\Service'] as $original) {
        if (class_exists($original, false)) {
            throw new RuntimeException('An unprefixed dependency leaked: ' . $original);
        }
    }
}

echo 'PASS: Plugin bootstrap, PSR-4 mapping, ICS parsing and recurrence expansion with ' . ($prefix === '' ? 'Composer' : 'isolated') . ' dependencies.' . PHP_EOL;
