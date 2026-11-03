<?php

namespace SW\Calendar\Tests\Performance;

use DateTimeImmutable;
use WP_REST_Request;

/** Repeatable local service/REST benchmark; never run against a site's database. */
final class CalendarPerformanceTest extends \SWC_TestCase
{
    private array $measurements = array();

    private function measure(string $name, callable $action): mixed
    {
        global $wpdb;
        $queries = $wpdb->num_queries;
        $memory = memory_get_usage(true);
        $start = hrtime(true);
        $result = $action();
        $this->measurements[ $name ][] = array(
            'ms' => ( hrtime(true) - $start ) / 1e6,
            'queries' => $wpdb->num_queries - $queries,
            'memory_delta_bytes' => memory_get_usage(true) - $memory,
        );
        return $result;
    }

    private function generatedFeed(bool $changed = false, int $count = 2000): string
    {
        $feed = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//SW//Performance//EN\r\n";
        for ($i = 0; $i < $count; ++$i) {
            // Unique UID even when title/time coincide: no duplicate lookup in fixture generation.
            $start = new DateTimeImmutable('2026-11-01T08:00:00Z');
            $start = $start->modify('+' . ( $i % 30 ) . ' days')->modify('+' . ( $i % 8 ) . ' hours');
            $title = $changed && $i < 100 ? 'Changed' : 'Appointment';
            $feed .= "BEGIN:VEVENT\r\nUID:perf-$i@example.org\r\nDTSTAMP:20261001T000000Z\r\nDTSTART:" . $start->format('Ymd\THis\Z') . "\r\nDTEND:" . $start->modify('+1 hour')->format('Ymd\THis\Z') . "\r\nSUMMARY:$title $i\r\nLOCATION:Room " . ( $i % 10 ) . "\r\nEND:VEVENT\r\n";
        }
        return $feed . "END:VCALENDAR\r\n";
    }

    public function testMonthWithTwoThousandEvents(): void
    {
        \SW\Calendar\Infrastructure\WordPress\Settings::update(array( 'public_rate_limit' => 0 ));
        $source = $this->source($this->ics_connection());
        $feed = $this->generatedFeed(); // Fixture generation is outside all measured actions.
        $sync = function (string $name, string $body, int $status = 200) use ($source) {
            $this->http->push($status, $body);
            $report = $this->measure($name, fn () => $this->services->sync()->sync($source));
            $this->assertSame('success', $report->status, (string) $report->message);
            return $report;
        };
        $this->assertSame(2000, $sync('import', $feed)->metrics->created);
        $this->assertSame(0, $sync('sync_unchanged', $feed)->metrics->created);
        $this->assertTrue($sync('sync_304', '', 304)->metrics->notModified);

        $view = $this->services->views()->save(null, 'Performance', true, array(
            'source_ids' => array($source),
            'display_mode' => 'list',
            'allowed_modes' => array('list', 'cards', 'month'),
            'html_cache' => true,
        ));
        $base = array( 'start' => '2026-11-01', 'end' => '2026-12-01', 'limit' => '50' );
        $request = function (string $path, array $params) {
            $r = new WP_REST_Request('GET', '/sw-cloudcalendar/v1' . $path);
            $r->set_query_params($params);
            $response = rest_get_server()->dispatch($r);
            $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
            return $response->get_data();
        };
        // Initialize routes outside measurements.
        rest_get_server();
        $events = $request("/views/$view/events", $base);
        $this->assertSame(2000, $events['pagination']['total']);
        $id = $events['events'][0]['id'];
        $scenarios = array(
            'events' => array( "/views/$view/events", $base ),
            'page_20' => array( "/views/$view/events", $base + array( 'page' => '20' ) ),
            'search' => array( "/views/$view/events", $base + array( 'search' => 'Appointment 1999' ) ),
            'detail' => array( "/events/$id", array( 'view' => $view ) ),
        );
        foreach (array( 'list', 'cards', 'month' ) as $mode) {
            $scenarios[ 'render_' . $mode ] = array( "/views/$view/render", $base + array( 'view' => $mode, 'month' => '2026-11' ) );
        }
        $renderedCounts = array();
        foreach ($scenarios as $name => [ $path, $params ]) {
            // Cold means HTML cache invalidated, not a cold OS/database or PHP process.
            $this->services->cache()->clear();
            $data = $this->measure($name . '_cold', fn () => $request($path, $params));
            if (str_starts_with($name, 'render_')) {
                $mode = substr($name, 7);
                $this->assertStringContainsString('data-swc-mode="' . $mode . '"', $data['html']);
                $this->assertSame(2000, $data['total']);
                preg_match_all('/data-swc-event="(\d+)"/', $data['html'], $matches);
                $renderedCounts[$mode] = count(array_unique($matches[1]));
                $this->assertGreaterThan(0, $renderedCounts[$mode]);
            }
            if ($name === 'search') {
                $this->assertSame(1, $data['pagination']['total']);
            }
            if ($name === 'page_20') {
                $this->assertSame(20, $data['pagination']['page']);
                $this->assertNotSame($id, $data['events'][0]['id']);
            }
            for ($i = 0; $i < 10; ++$i) {
                $this->measure($name . '_warm', fn () => $request($path, $params));
            }
        }
        $changed = $this->generatedFeed(true);
        $this->assertSame(100, $sync('sync_update_100', $changed)->metrics->updated);
        $deleted = $this->generatedFeed(true, 1900);
        $this->assertSame(100, $sync('sync_delete_100', $deleted)->metrics->deleted);
        $this->assertSame(1900, $request("/views/$view/events", $base)['pagination']['total']);

        $summary = array();
        foreach ($this->measurements as $name => $samples) {
            $times = array_column($samples, 'ms');
            sort($times);
            $summary[ $name ] = array(
                'samples' => count($times),
                'median_ms' => ($times[(int) floor((count($times) - 1) / 2)] + $times[(int) floor(count($times) / 2)]) / 2,
                'p95_ms' => $times[ (int) ceil(count($times) * 0.95) - 1 ],
                'max_queries' => max(array_column($samples, 'queries')),
            );
        }
        $report = array(
            'php' => PHP_VERSION,
            'wordpress' => get_bloginfo('version'),
            'database' => $GLOBALS['wpdb']->db_version(),
            'events' => 2000,
            'month' => '2026-11',
            'created_at_utc' => gmdate('c'),
            'xdebug_mode' => getenv('XDEBUG_MODE') ?: 'default',
            'scope' => 'sw-cloudcalendar local sync and REST actions; no unloaded-plugin control',
            'rendered_unique_events' => $renderedCounts,
            'peak_process_bytes' => memory_get_peak_usage(true),
            'summary' => $summary,
            'raw' => $this->measurements,
        );
        $path = getenv('SWC_PERF_REPORT') ?: sys_get_temp_dir() . '/swc-performance.json';
        $this->assertNotFalse(file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"));
    }
}
