<?php

use SW\Calendar\Application\Local\LocalEvent;
use SW\Calendar\Application\Local\LocalEventPage;
use SW\Calendar\Application\Local\LocalEventQuery;
use SW\Calendar\Application\Local\LocalEventSourceInterface;
use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\ConnectionStatus;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Domain\Source\Visibility;

final class LocalSourceEventsTest extends SWC_TestCase {
	private LocalEventSourceInterface $adapter;
	private int $source_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin();
		$this->adapter = new class() implements LocalEventSourceInterface {
			public bool $allowed = true;
			public bool $fail = false;
			public array $queries = array();
			public ?int $user = null;
			public function name(): string { return 'Preview'; }
			public function calendars(): array { return array(); }
			public function canReadCalendar( string $calendarId, ?int $userId ): bool {
				$this->user = $userId;
				return $this->allowed;
			}
			public function query( LocalEventQuery $query ): LocalEventPage {
				$this->queries[] = $query;
				if ( $this->fail ) { throw new RuntimeException( 'Secret adapter detail' ); }
				$start = new DateTimeImmutable( '2026-10-25T00:00:00Z' );
				return new LocalEventPage( array(
					new LocalEvent( 'one', 'cal', '<b>Title</b>', $start, $start->modify( '+1 day' ), allDay: true, location: 'Hall' ),
					new LocalEvent( 'other', 'foreign', 'Wrong calendar', $start, $start->modify( '+1 hour' ) ),
					new LocalEvent( 'cancelled', 'cal', 'Cancelled', $start, $start->modify( '+1 hour' ), status: \SW\Calendar\Domain\Event\OccurrenceStatus::Cancelled ),
				), 25 );
			}
			public function event( string $calendarId, string $id ): ?LocalEvent { return null; }
		};
		$this->services->local_sources()->register( 'preview', $this->adapter );
		$connection = $this->services->connections()->create( Provider::Local, 'Local', AuthMode::None, ConnectionStatus::Connected, array( 'adapter' => 'preview' ) );
		$this->source_id = $this->services->sources()->create( $connection, 'cal', 'Draft', '#336699', 'Europe/Berlin', Visibility::Authenticated, array(), SourceSettings::fromArray( array() ) );
	}

	public function test_admin_can_preview_drafts_with_bounded_month_and_pagination(): void {
		$response = $this->rest( 'GET', '/admin/sources/' . $this->source_id . '/events', array( 'month' => '2026-10', 'page' => 2, 'per_page' => 10 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 25, $response->get_data()['total'] );
		$this->assertCount( 1, $response->get_data()['items'] );
		$this->assertSame( '<b>Title</b>', $response->get_data()['items'][0]['title'] );
		$this->assertTrue( $response->get_data()['items'][0]['all_day'] );
		$query = $this->adapter->queries[0];
		$this->assertSame( array( 'cal' ), $query->calendarIds );
		$this->assertSame( 10, $query->offset );
		$this->assertSame( 10, $query->limit );
		$this->assertSame( '2026-09-30T22:00:00+00:00', $query->range->startUtc->format( DATE_ATOM ) );
		$this->assertSame( '2026-10-31T23:00:00+00:00', $query->range->endUtc->format( DATE_ATOM ) );
		$this->assertSame( get_current_user_id(), $this->adapter->user );
	}

	public function test_default_month_and_invalid_month(): void {
		$this->assertSame( 200, $this->rest( 'GET', '/admin/sources/' . $this->source_id . '/events' )->get_status() );
		$this->assertSame( '2026-10-01', $this->adapter->queries[0]->range->floatingStart->format( 'Y-m-d' ) );
		foreach ( array( '2026-13', '2026-02-31', '0000-01' ) as $month ) {
			$this->assertSame( 400, $this->rest( 'GET', '/admin/sources/' . $this->source_id . '/events', array( 'month' => $month ) )->get_status() );
		}
		$this->assertSame( 200, $this->rest( 'GET', '/admin/sources/' . $this->source_id . '/events', array( 'per_page' => 101 ) )->get_status() );
		$this->assertSame( 100, $this->adapter->queries[1]->limit );
	}

	public function test_adapter_denial_and_failure(): void {
		$this->adapter->allowed = false;
		$this->assertSame( 403, $this->rest( 'GET', '/admin/sources/' . $this->source_id . '/events' )->get_status() );
		$this->assertCount( 0, $this->adapter->queries );
		$this->adapter->allowed = true;
		$this->adapter->fail = true;
		$response = $this->rest( 'GET', '/admin/sources/' . $this->source_id . '/events' );
		$this->assertSame( 500, $response->get_status() );
		$this->assertStringNotContainsString( 'Secret adapter detail', wp_json_encode( $response->get_data() ) );
	}

	public function test_admin_permission_and_missing_sources(): void {
		$path = '/admin/sources/' . $this->source_id . '/events';
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', $path )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', $path )->get_status() );
		$this->assertCount( 0, $this->adapter->queries );
		$this->admin();
		$this->assertSame( 404, $this->rest( 'GET', '/admin/sources/999999/events' )->get_status() );
		$stored = $this->source( $this->ics_connection() );
		$this->assertSame( 200, $this->rest( 'GET', '/admin/sources/' . $stored . '/events' )->get_status() );
	}

	public function test_stored_events_are_scoped_to_source_and_month_and_paginated(): void {
		$source = $this->source( $this->ics_connection() );
		$other = $this->source( $this->ics_connection() );
		$start = new DateTimeImmutable( '2026-10-25T12:00:00Z' );
		$first = new LocalEvent( 'a', 'stored', 'First', $start, $start->modify( '+1 hour' ), location: 'Room' );
		$second = new LocalEvent( 'b', 'stored', 'Second', $start->modify( '+1 day' ), $start->modify( '+1 day +1 hour' ) );
		$cancelled = new LocalEvent( 'c', 'stored', 'Cancelled', $start, $start->modify( '+1 hour' ), status: \SW\Calendar\Domain\Event\OccurrenceStatus::Cancelled );
		$this->services->occurrences()->replaceSnapshot( $source, array( $first->occurrence( $source ), $second->occurrence( $source ), $cancelled->occurrence( $source ) ), 1 );
		$this->services->occurrences()->replaceSnapshot( $other, array( $first->occurrence( $other ) ), 1 );
		$response = $this->rest( 'GET', '/admin/sources/' . $source . '/events', array( 'month' => '2026-10', 'page' => 2, 'per_page' => 1 ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['total'] );
		$this->assertSame( 'Second', $response->get_data()['items'][0]['title'] );
		$this->assertSame( '2026-10-26T12:00:00+00:00', $response->get_data()['items'][0]['start'] );
		$this->assertSame( array(), $this->rest( 'GET', '/admin/sources/' . $source . '/events', array( 'month' => '2026-11' ) )->get_data()['items'] );
		$this->assertCount( 0, $this->adapter->queries );
	}

	public function test_missing_adapter(): void {
		$connection = $this->services->connections()->create( Provider::Local, 'Missing', AuthMode::None, ConnectionStatus::Connected, array( 'adapter' => 'absent' ) );
		$source = $this->source( $connection );
		$this->assertSame( 503, $this->rest( 'GET', '/admin/sources/' . $source . '/events' )->get_status() );
	}
}
