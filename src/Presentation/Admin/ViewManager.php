<?php
/**
 * Calendar view administration.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Admin;

use SW\Calendar\Application\Render\FilterSchema;
use SW\Calendar\Domain\Template\TemplateKind;
use SW\Calendar\Domain\View\CalendarView;
use SW\Calendar\Domain\View\DisplayMode;
use SW\Calendar\Infrastructure\Database\ViewRepository;
use SW\Calendar\Services;

final class ViewManager {

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array( CalendarView $view ): array {
		return array(
			'id'                    => $view->id,
			'name'                  => $view->name,
			'enabled'               => $view->enabled,
			'revision'              => $view->revision,
			'source_ids'            => $view->sourceIds,
			'default_filters'       => (object) $view->defaultFilters,
			'display_mode'          => $view->displayMode->value,
			'allowed_modes'         => array_map( static fn ( DisplayMode $mode ): string => $mode->value, $view->allowedModes ),
			'container_template_id' => $view->containerTemplateId,
			'event_template_id'     => $view->eventTemplateId,
			'detail_template_id'    => $view->detailTemplateId,
			'allowed_template_ids'  => $view->allowedTemplateIds,
			'timezone'              => $view->timezone,
			'language'              => $view->language,
			'color_rules'           => (object) $view->colorRules,
			'html_cache'            => $view->htmlCache,
			'date_format'           => $view->dateFormat,
			'time_format'           => $view->timeFormat,
			'shortcode'             => '[sw_calendar id="' . $view->id . '"]',
			'dynamic'               => $this->services->renderer()->requiresDynamicLoading( $view->id ),
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function save( ?int $id, array $data ): CalendarView {
		$existing = null === $id ? null : $this->services->views()->find( $id );
		if ( null !== $id && null === $existing ) {
			throw new ValidationException( array( 'id' => 'not_found' ), 'View not found.', 404 );
		}
		[ $name, $enabled, $config ] = $this->validated( $existing, $data );
		$id                          = $this->services->views()->save( $id, $name, $enabled, $config );
		$this->services->audit()->record( null === $existing ? 'view_created' : 'view_updated', 'view', $id );
		return $this->services->views()->find( $id );
	}

	/**
	 * Unsaved view object for the admin live preview (id 0, never cached, ACL of the current user applies).
	 *
	 * @param array<string, mixed> $data
	 */
	public function unsaved( ?int $id, array $data ): CalendarView {
		$existing                    = null === $id ? null : $this->services->views()->find( $id );
		[ $name, $enabled, $config ] = $this->validated( $existing, $data );
		return ViewRepository::map(
			array(
				'id'          => 0,
				'name'        => $name,
				'enabled'     => $enabled ? 1 : 0,
				'config_json' => (string) wp_json_encode( $config ),
				'revision'    => 0,
			)
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{0: string, 1: bool, 2: array<string, mixed>}
	 */
	private function validated( ?CalendarView $existing, array $data ): array {
		$current = null === $existing ? array() : $this->to_array( $existing );
		$data    = array_merge( $current, $data );
		$errors  = array();

		$name = trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );
		if ( '' === $name || mb_strlen( $name ) > 190 ) {
			$errors['name'] = 'invalid';
		}
		$known_sources = array_keys( $this->services->sources()->findMany( array_map( 'intval', (array) ( $data['source_ids'] ?? array() ) ) ) );
		$source_ids    = array_values( array_unique( array_map( 'intval', (array) ( $data['source_ids'] ?? array() ) ) ) );
		if ( array_diff( $source_ids, $known_sources ) !== array() ) {
			$errors['source_ids'] = 'unknown_source';
		}
		$mode = DisplayMode::tryFrom( (string) ( $data['display_mode'] ?? 'list' ) );
		if ( null === $mode ) {
			$errors['display_mode'] = 'invalid';
		}
		$allowed_modes = array();
		foreach ( (array) ( $data['allowed_modes'] ?? array() ) as $value ) {
			$parsed = DisplayMode::tryFrom( (string) $value );
			if ( null === $parsed ) {
				$errors['allowed_modes'] = 'invalid';
				continue;
			}
			$allowed_modes[] = $parsed->value;
		}
		$templates    = array(
			'container_template_id' => TemplateKind::Container,
			'event_template_id'     => TemplateKind::Event,
			'detail_template_id'    => TemplateKind::Detail,
		);
		$template_ids = array();
		foreach ( $templates as $field => $kind ) {
			$value = $data[ $field ] ?? null;
			if ( null === $value || '' === $value || 0 === (int) $value ) {
				$template_ids[ $field ] = null;
				continue;
			}
			$template = $this->services->templates()->find( (int) $value );
			if ( null === $template || $template->kind !== $kind ) {
				$errors[ $field ] = 'invalid_template';
				continue;
			}
			$template_ids[ $field ] = $template->id;
		}
		$allowed_templates = array();
		foreach ( (array) ( $data['allowed_template_ids'] ?? array() ) as $value ) {
			$template = $this->services->templates()->find( (int) $value );
			if ( null === $template || TemplateKind::Event !== $template->kind ) {
				$errors['allowed_template_ids'] = 'invalid_template';
				continue;
			}
			$allowed_templates[] = $template->id;
		}
		$language = (string) ( $data['language'] ?? '' );
		if ( '' !== $language && 1 !== preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/', $language ) ) {
			$errors['language'] = 'invalid';
		}
		$timezone = (string) ( $data['timezone'] ?? '' );
		if ( '' !== $timezone && ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
			$errors['timezone'] = 'invalid';
		}
		$defaults = is_array( $data['default_filters'] ?? null ) ? $data['default_filters'] : (array) ( $data['default_filters'] ?? array() );
		$parsed   = ( new FilterSchema() )->normalize( $defaults );
		foreach ( $parsed->errors as $field => $code ) {
			$errors[ 'default_filters.' . $field ] = $code;
		}
		foreach ( $parsed->unknown as $field ) {
			$errors[ 'default_filters.' . $field ] = 'unknown';
		}
		$colors = array();
		foreach ( (array) ( $data['color_rules'] ?? array() ) as $source_id => $color ) {
			if ( ! in_array( (int) $source_id, $source_ids, true ) || 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $color ) ) {
				$errors['color_rules'] = 'invalid';
				continue;
			}
			$colors[ (int) $source_id ] = strtolower( (string) $color );
		}
		$formats = array();
		foreach ( array( 'date_format', 'time_format' ) as $field ) {
			$value = (string) ( $data[ $field ] ?? '' );
			if ( mb_strlen( $value ) > 40 || 1 === preg_match( '/[<>"]/', $value ) ) {
				$errors[ $field ] = 'invalid';
			}
			$formats[ $field ] = $value;
		}
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}
		$config = array(
			'source_ids'            => $source_ids,
			'default_filters'       => array_intersect_key( $defaults, $parsed->filter->toArray() + array( 'source' => 1 ) ),
			'display_mode'          => $mode->value,
			'allowed_modes'         => array_values( array_unique( $allowed_modes ) ),
			'container_template_id' => $template_ids['container_template_id'],
			'event_template_id'     => $template_ids['event_template_id'],
			'detail_template_id'    => $template_ids['detail_template_id'],
			'allowed_template_ids'  => array_values( array_unique( $allowed_templates ) ),
			'timezone'              => $timezone,
			'language'              => $language,
			'color_rules'           => $colors,
			'html_cache'            => (bool) ( $data['html_cache'] ?? true ),
			'date_format'           => $formats['date_format'],
			'time_format'           => $formats['time_format'],
		);
		return array( $name, (bool) ( $data['enabled'] ?? true ), $config );
	}

	public function delete( int $id ): void {
		if ( null === $this->services->views()->find( $id ) ) {
			throw new ValidationException( array( 'id' => 'not_found' ), 'View not found.', 404 );
		}
		$this->services->views()->delete( $id );
		$this->services->audit()->record( 'view_deleted', 'view', $id );
	}
}
