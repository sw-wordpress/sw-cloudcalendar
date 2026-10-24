<?php
/**
 * Source administration: validation, explicit publication decisions, immediate revocation.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Admin;

use SW\Calendar\Domain\Connection\AuthMode;
use SW\Calendar\Domain\Connection\Connection;
use SW\Calendar\Domain\Job\JobType;
use SW\Calendar\Domain\Source\AttachmentPolicy;
use SW\Calendar\Domain\Source\Confirmation;
use SW\Calendar\Domain\Source\Source;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Domain\Source\SourceStatus;
use SW\Calendar\Domain\Source\Visibility;
use SW\Calendar\Services;

/**
 * Rules (SPECIFICATIONS.md §3.2, §8.1, §7.2):
 * - New sources start as `draft` (never visible before an explicit activation).
 * - A WordPress-public source from an authenticated (private) provider calendar needs an explicit confirmation.
 * - `public_only=false` on a public source needs a second, separate confirmation for private files.
 * - Confirmations are recorded with time, user and source revision in the source and in the audit log; leaving
 *   `public` clears them.
 * - Any change of visibility, roles, status or attachment policy revokes the delivery of stored files immediately
 *   (the next sync re-checks them) and bumps the revision (render cache keys).
 */
final class SourceManager {

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array( Source $source ): array {
		$state      = $this->services->sync_state()->load( $source->id );
		$connection = $this->services->connections()->find( $source->connectionId );
		return array(
			'id'                    => $source->id,
			'connection_id'         => $source->connectionId,
			'provider'              => $connection?->provider->value,
			'external_id'           => $source->externalId,
			'name'                  => $source->name,
			'color'                 => $source->color,
			'timezone'              => $source->timezone,
			'visibility'            => $source->visibility->value,
			'roles'                 => $source->roles,
			'status'                => $source->status->value,
			'revision'              => $source->revision,
			'settings'              => $source->settings->toArray(),
			'requires_confirmation' => null !== $connection && $this->provider_private( $connection ),
			'last_success_at'       => $source->lastSuccessAt?->format( 'Y-m-d\TH:i:s\Z' ),
			'next_attempt_at'       => $state->nextAttemptAt?->format( 'Y-m-d\TH:i:s\Z' ),
			'last_error_code'       => $state->lastErrorCode,
			'failures'              => $state->failures,
			'syncing'               => null !== $state->lockedUntil && $state->lockedUntil > $this->services->clock()->now(),
			'sync_queued'           => $this->services->jobs()->has_pending( JobType::SyncSource, $source->id ),
			'occurrence_count'      => $this->services->occurrences()->count_for_source( $source->id ),
			'attachments'           => $this->services->attachments()->stats( $source->id ),
			'storage_safe'          => $this->services->storage_guard()->isSafe(),
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function save( ?int $id, array $data ): Source {
		$existing = null === $id ? null : $this->services->sources()->find( $id );
		if ( null !== $id && null === $existing ) {
			throw new ValidationException( array( 'id' => 'not_found' ), 'Source not found.', 404 );
		}
		$errors        = array();
		$connection_id = $existing?->connectionId ?? (int) ( $data['connection_id'] ?? 0 );
		$connection    = $this->services->connections()->find( $connection_id );
		if ( null === $connection ) {
			throw new ValidationException( array( 'connection_id' => 'not_found' ) );
		}
		$external_id = $existing?->externalId ?? trim( (string) ( $data['external_id'] ?? '' ) );
		if ( '' === $external_id || strlen( $external_id ) > 512 || 1 === preg_match( '/[\x00-\x1F]/', $external_id ) ) {
			$errors['external_id'] = 'invalid';
		}
		$name = trim( sanitize_text_field( (string) ( $data['name'] ?? $existing?->name ?? '' ) ) );
		if ( '' === $name || mb_strlen( $name ) > 190 ) {
			$errors['name'] = 'invalid';
		}
		$color = strtolower( (string) ( $data['color'] ?? $existing?->color ?? '#3366cc' ) );
		if ( 1 !== preg_match( '/^#[0-9a-f]{6}$/', $color ) ) {
			$errors['color'] = 'invalid';
		}
		$timezone = (string) ( $data['timezone'] ?? $existing?->timezone ?? 'UTC' );
		if ( ! in_array( $timezone, timezone_identifiers_list(), true ) && 'UTC' !== $timezone ) {
			$errors['timezone'] = 'invalid';
		}
		$visibility = Visibility::tryFrom( (string) ( $data['visibility'] ?? $existing?->visibility->value ?? 'authenticated' ) );
		if ( null === $visibility ) {
			$errors['visibility'] = 'invalid';
			$visibility           = Visibility::Roles;
		}
		$roles = array_values( array_unique( array_map( 'strval', (array) ( $data['roles'] ?? $existing?->roles ?? array() ) ) ) );
		$known = array_keys( wp_roles()->get_names() );
		foreach ( $roles as $role ) {
			if ( ! in_array( $role, $known, true ) ) {
				$errors['roles'] = 'unknown_role';
			}
		}
		if ( Visibility::Roles === $visibility && array() === $roles ) {
			$errors['roles'] = 'required';
		}
		if ( Visibility::Roles !== $visibility ) {
			$roles = array();
		}
		$status = SourceStatus::tryFrom( (string) ( $data['status'] ?? $existing?->status->value ?? SourceStatus::Draft->value ) );
		if ( null === $status || ( null === $existing && SourceStatus::Draft !== $status ) || ( SourceStatus::Error === $status && SourceStatus::Error !== $existing?->status ) ) {
			// New sources are drafts; "error" is set by the sync engine only.
			$errors['status'] = 'invalid';
			$status           = $existing?->status ?? SourceStatus::Draft;
		}

		$settings_errors = array();
		$raw_settings    = array_replace_recursive( $existing?->settings->toArray() ?? array(), is_array( $data['settings'] ?? null ) ? $data['settings'] : array() );
		// Confirmations can only be granted through the explicit flags below, never by posting the stored structure.
		unset( $raw_settings['publication_confirmation'], $raw_settings['attachments']['publish_private_attachments_confirmation'] );
		$settings = SourceSettings::fromArray( $raw_settings, $this->services->max_window_days(), $settings_errors );
		foreach ( $settings_errors as $field ) {
			$errors[ 'settings.' . $field ] = 'invalid';
		}

		$now        = $this->services->clock()->now();
		$revision   = ( $existing?->revision ?? 0 ) + 1;
		$user       = get_current_user_id();
		$was_public = Visibility::Public === $existing?->visibility;
		$audit      = array();

		// Publication of a provider-private calendar.
		$publication = Visibility::Public === $visibility ? ( $was_public ? $existing?->settings->publicationConfirmation : null ) : null;
		if ( Visibility::Public === $visibility && $this->provider_private( $connection ) && null === $publication ) {
			if ( true === ( $data['confirm_publication'] ?? false ) ) {
				$publication = new Confirmation( $user, $now, $revision );
				$audit[]     = 'source_publication_confirmed';
			} else {
				$errors['confirm_publication'] = 'required';
			}
		}

		// Publication of private attachments on a public source.
		$file_confirmation = Visibility::Public === $visibility && $was_public ? $existing?->settings->attachments->privateAttachmentsConfirmation : null;
		if ( $settings->attachments->requiresPrivateConfirmation( $visibility ) && null === $file_confirmation ) {
			if ( true === ( $data['confirm_private_attachments'] ?? false ) ) {
				$file_confirmation = new Confirmation( $user, $now, $revision );
				$audit[]           = 'source_private_attachments_confirmed';
			} else {
				$errors['confirm_private_attachments'] = 'required';
			}
		}
		if ( ! $settings->attachments->requiresPrivateConfirmation( $visibility ) ) {
			$file_confirmation = null;
		}
		if ( $settings->attachments->syncAttachments && ! $this->services->storage_guard()->isSafe() ) {
			$errors['settings.attachments.sync_attachments'] = 'storage_unsafe';
		}
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}

		$attachments = new AttachmentPolicy(
			$settings->attachments->syncAttachments,
			$settings->attachments->publicOnly,
			$settings->attachments->maxFileSizeMb,
			$settings->attachments->allowedMimeTypes,
			$settings->attachments->generateImagePreviews,
			$settings->attachments->showImages,
			$settings->attachments->showFileList,
			$settings->attachments->quotaMb,
			$settings->attachments->refreshHours,
			$file_confirmation
		);
		$settings    = new SourceSettings(
			$settings->syncEnabled,
			$settings->syncIntervalMinutes,
			$settings->pastDays,
			$settings->futureDays,
			$settings->excludePrivateEvents,
			$settings->excludedEventTypes,
			$attachments,
			$publication
		);

		$repo = $this->services->sources();
		if ( null === $existing ) {
			$id = $repo->create( $connection->id, $external_id, $name, $color, $timezone, $visibility, $roles, $settings );
			$this->services->audit()->record( 'source_created', 'source', $id, array( 'visibility' => $visibility->value ) );
			$this->record_confirmations( $audit, $id, $revision );
			return $repo->find( $id );
		}

		$repo->update( (int) $id, $name, $color, $timezone, $visibility, $roles, $settings, $status );
		$this->record_confirmations( $audit, (int) $id, $revision );
		$acl_changed    = $existing->visibility !== $visibility || $existing->roles !== $roles || $existing->status !== $status;
		$policy_changed = $existing->settings->attachments->toArray() !== $attachments->toArray();
		if ( $acl_changed || $policy_changed ) {
			// Stop delivery at once; files that are still permitted are re-validated by the next sync.
			$this->services->attachments()->revokeSource( (int) $id );
			$this->services->audit()->record(
				'source_access_changed',
				'source',
				(int) $id,
				array(
					'visibility' => $visibility->value,
					'status'     => $status->value,
				)
			);
		}
		$window_changed  = $existing->settings->pastDays !== $settings->pastDays || $existing->settings->futureDays !== $settings->futureDays;
		$filters_changed = $existing->settings->excludePrivateEvents !== $settings->excludePrivateEvents || $existing->settings->excludedEventTypes !== $settings->excludedEventTypes;
		if ( $window_changed ) {
			$this->services->sync_state()->resetCursor( (int) $id );
		}
		if ( $window_changed || $filters_changed || $acl_changed || $policy_changed || $existing->timezone !== $timezone ) {
			$this->request_sync( (int) $id, $window_changed );
		}
		$this->services->invalidator()->sourceChanged( (int) $id );
		return $repo->find( (int) $id );
	}

	/**
	 * @param list<string> $actions
	 */
	private function record_confirmations( array $actions, int $id, int $revision ): void {
		foreach ( $actions as $action ) {
			$this->services->audit()->record( $action, 'source', $id, array( 'revision' => $revision ) );
		}
	}

	/**
	 * Queues a sync job (never runs it in the request).
	 */
	public function request_sync( int $id, bool $full = false ): void {
		$source = $this->services->sources()->find( $id );
		if ( null === $source ) {
			throw new ValidationException( array( 'id' => 'not_found' ), 'Source not found.', 404 );
		}
		if ( $full ) {
			$this->services->sync_state()->resetCursor( $id );
		}
		$this->services->sync_state()->make_due( $id );
		$this->services->jobs()->enqueue( JobType::SyncSource, $id, array( 'full' => $full ), hash( 'sha256', 'sync_source|' . $id ), $this->services->clock()->now() );
		if ( function_exists( 'spawn_cron' ) && 'wp' === \SW\Calendar\Infrastructure\WordPress\Settings::get( 'cron_mode' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Removes the source and its data. Delivery stops immediately; files are removed by the storage cleanup.
	 */
	public function delete( int $id ): void {
		if ( null === $this->services->sources()->find( $id ) ) {
			throw new ValidationException( array( 'id' => 'not_found' ), 'Source not found.', 404 );
		}
		$this->services->sources()->setStatus( $id, SourceStatus::Disabled );
		$this->services->attachments()->deleteSource( $id );
		$this->services->occurrences()->deleteSource( $id );
		$this->services->items()->deleteSource( $id );
		$this->services->jobs()->deleteSource( $id );
		$this->services->sync_state()->delete( $id );
		$this->services->sources()->delete( $id );
		$this->services->invalidator()->sourceChanged( $id );
		$this->services->audit()->record( 'source_deleted', 'source', $id );
	}

	/**
	 * A calendar read with credentials is private at the provider unless proven otherwise (API key mode only works
	 * for calendars that Google already publishes).
	 */
	private function provider_private( Connection $connection ): bool {
		return in_array( $connection->authMode, array( AuthMode::OAuth, AuthMode::HttpHeader ), true );
	}
}
