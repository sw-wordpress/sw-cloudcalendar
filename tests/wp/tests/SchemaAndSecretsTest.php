<?php

use SW\Calendar\Infrastructure\Database\Schema;

final class SchemaAndSecretsTest extends SWC_TestCase {

	public function test_schema_is_installed_and_migration_is_idempotent(): void {
		$this->assertSame( array(), Schema::missing_tables() );
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( Schema::migrate() );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_sw_calendar' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'manage_sw_calendar' ) );
	}

	public function test_presets_are_seeded(): void {
		$this->assertGreaterThanOrEqual( 12, $this->services->templates()->count() );
	}

	public function test_secrets_are_encrypted_and_bound_to_their_row(): void {
		global $wpdb;
		$connection = $this->ics_connection();
		$this->services->credentials()->update( $connection, array( 'auth_header' => 'Basic c2VjcmV0OnZhbHVl' ) );
		$cipher = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ciphertext FROM ' . Schema::table( 'secrets' ) . ' WHERE connection_id = %d', $connection ) );
		$this->assertStringStartsWith( 'swc1:', $cipher );
		$this->assertStringNotContainsString( 'c2VjcmV0', $cipher );
		$this->assertSame( 'Basic c2VjcmV0OnZhbHVl', $this->services->credentials()->get( $connection )['auth_header'] );

		// A ciphertext copied to another connection must not decrypt there (context bound AEAD).
		$other = $this->ics_connection();
		$wpdb->insert( Schema::table( 'secrets' ), array( 'connection_id' => $other, 'ciphertext' => $cipher, 'key_version' => 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$this->expectException( \SW\Calendar\Domain\CalendarException::class );
		$this->services->credentials()->get( $other );
	}
}
