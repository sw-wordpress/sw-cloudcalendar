<?php
/**
 * Attachment records (one row per occurrence and provider reference; rows of one reference share the stored file).
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Infrastructure\Database;

use SW\Calendar\Application\Attachments\StoredFile;
use SW\Calendar\Application\Port\AttachmentQueryInterface;
use SW\Calendar\Application\Port\AttachmentStoreInterface;
use SW\Calendar\Domain\Attachment\Attachment;
use SW\Calendar\Domain\Attachment\PublicationState;
use SW\Calendar\Domain\Attachment\SyncState;
use SW\Calendar\Domain\Provider\ProviderAttachmentDefinition;

final class AttachmentRepository implements AttachmentStoreInterface, AttachmentQueryInterface {

	public function reconcile( int $sourceId, array $byOccurrence, \DateTimeImmutable $now, int $refreshHours ): array {
		$wpdb     = Db::wpdb();
		$table    = Schema::table( 'attachments' );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT id, occurrence_id, provider_ref_hash, sync_state, version_token, last_checked_at FROM {$table} WHERE source_id = %d", $sourceId ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = array();
		$by_ref   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$existing[ (int) $row['occurrence_id'] . '|' . $row['provider_ref_hash'] ] = $row;
			$by_ref[ (string) $row['provider_ref_hash'] ][]                            = $row;
		}

		$wanted  = array();
		$pending = array();
		$nowSql  = Db::format( $now );
		$stale   = Db::format( $now->modify( '-' . max( 1, $refreshHours ) . ' hours' ) );
		foreach ( $byOccurrence as $occurrence_id => $data ) {
			foreach ( $data['attachments'] as $definition ) {
				$ref                                   = $definition->refHash();
				$wanted[ $occurrence_id . '|' . $ref ] = true;
				$row                                   = $existing[ $occurrence_id . '|' . $ref ] ?? null;
				if ( null === $row ) {
					$this->insert_row( $sourceId, (int) $occurrence_id, (string) $data['provider_event_id'], $definition, $by_ref[ $ref ] ?? array(), $nowSql );
				} elseif ( SyncState::Revoked->value === $row['sync_state'] ) {
					// Re-added after a revocation: deliver only after a fresh policy check.
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$table,
						array(
							'sync_state' => SyncState::Pending->value,
							'revoked_at' => null,
							'updated_at' => $nowSql,
						),
						array( 'id' => (int) $row['id'] ),
						array( '%s', '%s', '%s' ),
						array( '%d' )
					); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				$state   = null === $row ? null : (string) $row['sync_state'];
				$version = (string) $definition->versionToken;
				$changed = null !== $row && '' !== $version && $version !== (string) $row['version_token'];
				$expired = null !== $row && null !== $row['last_checked_at'] && (string) $row['last_checked_at'] < $stale;
				$unknown = null === $row && ! $this->has_stored_sibling( $by_ref[ $ref ] ?? array(), $version );
				if ( $unknown || $changed || $expired || in_array( $state, array( SyncState::Pending->value, SyncState::Failed->value, SyncState::Revoked->value ), true ) ) {
					$pending[ $ref ] = array(
						'ref_hash'          => $ref,
						'provider_event_id' => (string) $data['provider_event_id'],
						'version'           => $version . '|' . ( $expired ? substr( $nowSql, 0, 13 ) : '' ),
					);
				}
			}
		}

		$revoke = array();
		foreach ( $existing as $key => $row ) {
			if ( ! isset( $wanted[ $key ] ) && SyncState::Revoked->value !== $row['sync_state'] ) {
				$revoke[] = (int) $row['id'];
			}
		}
		foreach ( array_chunk( $revoke, 500 ) as $chunk ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET sync_state = %s, revoked_at = %s, updated_at = %s WHERE id IN (" . Db::int_placeholders( $chunk ) . ')', SyncState::Revoked->value, $nowSql, $nowSql, ...$chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}
		return array(
			'pending' => array_values( $pending ),
			'revoked' => count( $revoke ),
		);
	}

	public function forReference( int $sourceId, string $refHash ): array {
		$table = Schema::table( 'attachments' );
		$rows  = Db::wpdb()->get_results( Db::wpdb()->prepare( "SELECT * FROM {$table} WHERE source_id = %d AND provider_ref_hash = %s AND sync_state <> %s ORDER BY id ASC", $sourceId, $refHash, SyncState::Revoked->value ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( $this, 'map' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Raw definition fields of a reference (needed by the download job).
	 *
	 * @return array{kind: string, reference: string, filename: string, mime: string, size: int, version: ?string, provider_event_id: string}|null
	 */
	public function definition( int $sourceId, string $refHash ): ?array {
		$table = Schema::table( 'attachments' );
		$row   = Db::wpdb()->get_row( Db::wpdb()->prepare( "SELECT ref_kind, provider_id, filename, mime, size_bytes, version_token, provider_event_id FROM {$table} WHERE source_id = %d AND provider_ref_hash = %s ORDER BY id DESC LIMIT 1", $sourceId, $refHash ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'kind'              => (string) $row['ref_kind'],
			'reference'         => (string) $row['provider_id'],
			'filename'          => (string) $row['filename'],
			'mime'              => (string) $row['mime'],
			'size'              => (int) $row['size_bytes'],
			'version'           => null === $row['version_token'] ? null : (string) $row['version_token'],
			'provider_event_id' => (string) $row['provider_event_id'],
		);
	}

	public function markStored( int $sourceId, string $refHash, StoredFile $file, PublicationState $state, \DateTimeImmutable $now ): void {
		$table = Schema::table( 'attachments' );
		Db::wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"UPDATE {$table} SET storage_key = %s, preview_key = %s, sha256 = %s, mime = %s, size_bytes = %d, is_image = %d, version_token = %s, filename = %s,
				publication_state = %s, sync_state = %s, last_checked_at = %s, last_error = NULL, updated_at = %s
				WHERE source_id = %d AND provider_ref_hash = %s AND sync_state <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$file->storageKey,
				(string) $file->previewKey,
				$file->sha256,
				$file->mime,
				$file->sizeBytes,
				$file->isImage ? 1 : 0,
				(string) $file->versionToken,
				mb_substr( self::safe_filename( $file->filename ), 0, 255 ),
				$state->value,
				SyncState::Stored->value,
				Db::format( $now ),
				Db::format( $now ),
				$sourceId,
				$refHash,
				SyncState::Revoked->value
			)
		);
	}

	public function markSkipped( int $sourceId, string $refHash, PublicationState $state, string $reason, \DateTimeImmutable $now ): void {
		$this->set_state( $sourceId, $refHash, SyncState::Skipped, $state, $reason, $now );
	}

	public function markFailed( int $sourceId, string $refHash, string $error, bool $keepDelivering, \DateTimeImmutable $now ): void {
		$table = Schema::table( 'attachments' );
		if ( $keepDelivering ) {
			// Only the error is recorded; already stored (and still permitted) files stay deliverable.
			Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET last_error = %s, updated_at = %s WHERE source_id = %d AND provider_ref_hash = %s AND sync_state = %s", mb_substr( $error, 0, 500 ), Db::format( $now ), $sourceId, $refHash, SyncState::Stored->value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET sync_state = %s, last_error = %s, updated_at = %s WHERE source_id = %d AND provider_ref_hash = %s AND sync_state = %s", SyncState::Failed->value, mb_substr( $error, 0, 500 ), Db::format( $now ), $sourceId, $refHash, SyncState::Pending->value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		$this->set_state( $sourceId, $refHash, SyncState::Failed, null, $error, $now );
	}

	public function revokeSource( int $sourceId ): int {
		$table = Schema::table( 'attachments' );
		return (int) Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET sync_state = %s, revoked_at = %s, updated_at = %s WHERE source_id = %d AND sync_state <> %s", SyncState::Revoked->value, Db::now(), Db::now(), $sourceId, SyncState::Revoked->value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function unreferencedStorageKeys( \DateTimeImmutable $revokedBefore, int $limit ): array {
		$table = Schema::table( 'attachments' );
		$keys  = Db::wpdb()->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"SELECT storage_key FROM {$table} WHERE storage_key IS NOT NULL AND storage_key <> ''
				GROUP BY storage_key
				HAVING SUM(sync_state IN (%s, %s)) = 0 AND MAX(updated_at) < %s
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				SyncState::Stored->value,
				SyncState::Pending->value,
				Db::format( $revokedBefore ),
				$limit
			)
		);
		return array_map( 'strval', is_array( $keys ) ? $keys : array() );
	}

	/**
	 * References of a source that wait for (another) download attempt.
	 *
	 * @return list<string>
	 */
	public function pending_refs( int $source_id ): array {
		$table = Schema::table( 'attachments' );
		$refs  = Db::wpdb()->get_col( Db::wpdb()->prepare( "SELECT DISTINCT provider_ref_hash FROM {$table} WHERE source_id = %d AND sync_state IN (%s, %s)", $source_id, 'pending', 'failed' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'strval', is_array( $refs ) ? $refs : array() );
	}

	/**
	 * @return list<string>
	 */
	public function preview_keys_for( string $storage_key ): array {
		$table = Schema::table( 'attachments' );
		$keys  = Db::wpdb()->get_col( Db::wpdb()->prepare( "SELECT DISTINCT preview_key FROM {$table} WHERE storage_key = %s AND preview_key IS NOT NULL AND preview_key <> ''", $storage_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'strval', is_array( $keys ) ? $keys : array() );
	}

	public function forgetStorageKey( string $storageKey ): void {
		$table = Schema::table( 'attachments' );
		Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET storage_key = NULL, preview_key = NULL WHERE storage_key = %s", $storageKey ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Rows that are revoked and have no file any more are not needed for anything.
		Db::wpdb()->query( Db::wpdb()->prepare( "DELETE FROM {$table} WHERE sync_state = %s AND storage_key IS NULL", SyncState::Revoked->value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function storedBytes( ?int $sourceId = null ): int {
		$table = Schema::table( 'attachments' );
		$sql   = "SELECT COALESCE(SUM(size_bytes), 0) FROM (SELECT storage_key, MAX(size_bytes) AS size_bytes FROM {$table} WHERE storage_key IS NOT NULL AND storage_key <> ''"
			. ( null === $sourceId ? '' : ' AND source_id = %d' ) . ' GROUP BY storage_key) files';
		return (int) ( null === $sourceId
			? Db::wpdb()->get_var( $sql ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			: Db::wpdb()->get_var( Db::wpdb()->prepare( $sql, $sourceId ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	public function deleteSource( int $sourceId ): void {
		// Files are removed by the storage garbage collection; rows are revoked first so delivery stops at once.
		$this->revokeSource( $sourceId );
	}

	public function deliverableFor( array $occurrenceIds ): array {
		$ids = array_values( array_filter( array_map( 'intval', $occurrenceIds ), static fn ( int $id ): bool => $id > 0 ) );
		if ( array() === $ids ) {
			return array();
		}
		$table  = Schema::table( 'attachments' );
		$sql    = "SELECT * FROM {$table} WHERE occurrence_id IN (" . Db::int_placeholders( $ids ) . ") AND sync_state = %s AND storage_key IS NOT NULL AND storage_key <> '' ORDER BY is_image DESC, filename ASC, id ASC";
		$rows   = Db::wpdb()->get_results( Db::wpdb()->prepare( $sql, ...array_merge( $ids, array( SyncState::Stored->value ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$attachment                            = $this->map( $row );
			$result[ $attachment->occurrenceId ][] = $attachment;
		}
		return $result;
	}

	public function find( int $id ): ?Attachment {
		$table = Schema::table( 'attachments' );
		$row   = Db::wpdb()->get_row( Db::wpdb()->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $this->map( $row ) : null;
	}

	/**
	 * Diagnostics per source: counts per sync/publication state and last errors (no file names of private files).
	 *
	 * @return array<string, mixed>
	 */
	public function stats( int $source_id ): array {
		$table  = Schema::table( 'attachments' );
		$rows   = Db::wpdb()->get_results( Db::wpdb()->prepare( "SELECT sync_state, publication_state, COUNT(DISTINCT provider_ref_hash) AS files FROM {$table} WHERE source_id = %d GROUP BY sync_state, publication_state", $source_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$errors = Db::wpdb()->get_col( Db::wpdb()->prepare( "SELECT DISTINCT last_error FROM {$table} WHERE source_id = %d AND last_error IS NOT NULL ORDER BY updated_at DESC LIMIT 5", $source_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[] = array(
				'sync_state'        => (string) $row['sync_state'],
				'publication_state' => (string) $row['publication_state'],
				'files'             => (int) $row['files'],
			);
		}
		return array(
			'counts'      => $counts,
			'last_errors' => array_values( array_map( 'strval', is_array( $errors ) ? $errors : array() ) ),
			'bytes'       => $this->storedBytes( $source_id ),
		);
	}

	/**
	 * @param list<array<string, mixed>> $siblings
	 */
	private function insert_row( int $source_id, int $occurrence_id, string $provider_event_id, ProviderAttachmentDefinition $definition, array $siblings, string $now ): void {
		$table = Schema::table( 'attachments' );
		Db::wpdb()->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'source_id'         => $source_id,
				'occurrence_id'     => $occurrence_id,
				'provider_ref_hash' => $definition->refHash(),
				'ref_kind'          => $definition->kind,
				'provider_id'       => mb_substr( $definition->reference, 0, 2048 ),
				'provider_event_id' => mb_substr( $provider_event_id, 0, 512 ),
				'filename'          => mb_substr( self::safe_filename( $definition->filename ), 0, 255 ),
				'mime'              => mb_substr( strtolower( $definition->mime ), 0, 190 ),
				'size_bytes'        => max( 0, (int) $definition->sizeBytes ),
				'version_token'     => $definition->versionToken,
				'is_image'          => 0,
				'publication_state' => PublicationState::Unknown->value,
				'sync_state'        => SyncState::Pending->value,
				'updated_at'        => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
		$id = (int) Db::wpdb()->insert_id;
		// A new instance of a series shares the already stored (and permitted) file of its siblings.
		foreach ( $siblings as $sibling ) {
			if ( SyncState::Stored->value === $sibling['sync_state'] && (string) $sibling['version_token'] === (string) $definition->versionToken ) {
				Db::wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					Db::wpdb()->prepare(
						"UPDATE {$table} t JOIN {$table} s ON s.id = %d SET t.storage_key = s.storage_key, t.preview_key = s.preview_key, t.sha256 = s.sha256, t.mime = s.mime,
						t.size_bytes = s.size_bytes, t.is_image = s.is_image, t.publication_state = s.publication_state, t.sync_state = s.sync_state, t.last_checked_at = s.last_checked_at
						WHERE t.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						(int) $sibling['id'],
						$id
					)
				);
				return;
			}
		}
	}

	/**
	 * @param list<array<string, mixed>> $siblings
	 */
	private function has_stored_sibling( array $siblings, string $version ): bool {
		foreach ( $siblings as $sibling ) {
			if ( SyncState::Stored->value === $sibling['sync_state'] && (string) $sibling['version_token'] === $version ) {
				return true;
			}
		}
		return false;
	}

	private function set_state( int $source_id, string $ref_hash, SyncState $state, ?PublicationState $publication, string $error, \DateTimeImmutable $now ): void {
		$table = Schema::table( 'attachments' );
		Db::wpdb()->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::wpdb()->prepare(
				"UPDATE {$table} SET sync_state = %s, last_error = %s, last_checked_at = %s, updated_at = %s
				WHERE source_id = %d AND provider_ref_hash = %s AND sync_state <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$state->value,
				mb_substr( $error, 0, 500 ),
				Db::format( $now ),
				Db::format( $now ),
				$source_id,
				$ref_hash,
				SyncState::Revoked->value
			)
		);
		if ( null !== $publication ) {
			// wpdb::prepare() turns NULL into '', so the optional column is updated separately.
			Db::wpdb()->query( Db::wpdb()->prepare( "UPDATE {$table} SET publication_state = %s WHERE source_id = %d AND provider_ref_hash = %s AND sync_state <> %s", $publication->value, $source_id, $ref_hash, SyncState::Revoked->value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Display name only: path components, control characters and header breaking characters are removed.
	 */
	public static function safe_filename( string $name ): string {
		$name = str_replace( array( '\\', '/' ), '_', $name );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F"<>:|?*]+/u', '', $name );
		$name = trim( $name, " .\t\n\r" );
		return '' === $name ? 'attachment' : $name;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function map( array $row ): Attachment {
		$storage = (string) $row['storage_key'];
		$preview = (string) $row['preview_key'];
		return new Attachment(
			(int) $row['id'],
			(int) $row['source_id'],
			(int) $row['occurrence_id'],
			(string) $row['provider_ref_hash'],
			(string) $row['provider_id'],
			(string) $row['filename'],
			(string) $row['mime'],
			(int) $row['size_bytes'],
			null === $row['version_token'] ? null : (string) $row['version_token'],
			null === $row['sha256'] ? null : (string) $row['sha256'],
			'' === $storage ? null : $storage,
			'' === $preview ? null : $preview,
			(bool) $row['is_image'],
			PublicationState::tryFrom( (string) $row['publication_state'] ) ?? PublicationState::Unknown,
			SyncState::tryFrom( (string) $row['sync_state'] ) ?? SyncState::Revoked,
			Db::date( $row['last_checked_at'] ),
			null === $row['last_error'] ? null : (string) $row['last_error']
		);
	}
}
