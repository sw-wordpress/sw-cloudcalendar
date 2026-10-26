<?php
/**
 * Template administration, validation and preview.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar\Presentation\Admin;

use SW\Calendar\Application\Render\PresetLibrary;
use SW\Calendar\Application\Render\PreviewRenderer;
use SW\Calendar\Application\Render\Template\CssSanitizer;
use SW\Calendar\Application\Render\Template\PlaceholderRegistry;
use SW\Calendar\Application\Render\Template\TemplateSyntaxError;
use SW\Calendar\Application\Render\Template\TemplateValidationException;
use SW\Calendar\Domain\Template\EditorMode;
use SW\Calendar\Domain\Template\Template;
use SW\Calendar\Domain\Template\TemplateKind;
use SW\Calendar\Domain\View\DisplayMode;
use SW\Calendar\Infrastructure\WordPress\Settings;
use SW\Calendar\Services;

/**
 * Templates are validated by the context aware compiler and the CSS sanitizer before saving; they cannot contain
 * scripts, event handlers or external resources regardless of `unfiltered_html` (stricter than WordPress' own rule,
 * docs/templates.md). Only users with `manage_sw_calendar` reach this class.
 */
final class TemplateManager {

	public const MAX_SCHEMA_BYTES = 102400;

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array( Template $template, bool $with_body = true ): array {
		$data = array(
			'id'          => $template->id,
			'name'        => $template->name,
			'kind'        => $template->kind->value,
			'editor_mode' => $template->editorMode->value,
			'revision'    => $template->revision,
			'preset_key'  => $template->presetKey,
			'updated_at'  => $template->updatedAt?->format( 'Y-m-d\TH:i:s\Z' ),
			'used_by'     => $this->services->views()->ids_using_template( $template->id ),
		);
		if ( $with_body ) {
			$data['body']          = $template->body;
			$data['css']           = $template->css;
			$data['visual_schema'] = $template->visualSchema;
		}
		return $data;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function presets(): array {
		$library = $this->services->presets();
		$list    = array();
		foreach ( $library->keys() as $key ) {
			$meta  = $library->meta( $key );
			$parts = array();
			foreach ( TemplateKind::cases() as $kind ) {
				$parts[ $kind->value ] = array(
					'body' => (string) $library->body( $key, $kind ),
					'css'  => $library->css( $key, $kind ),
				);
			}
			$list[] = array(
				'key'         => $key,
				'name'        => $meta['name'],
				'mode'        => $meta['mode'],
				'description' => $meta['description'],
				'templates'   => $parts,
			);
		}
		return $list;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function reference(): array {
		$registry = new PlaceholderRegistry();
		$result   = array();
		foreach ( TemplateKind::cases() as $kind ) {
			$result[ $kind->value ] = $registry->describe( $kind );
		}
		return $result;
	}

	/**
	 * @return array{valid: bool, errors: list<array<string, mixed>>, css_error: ?string}
	 */
	public function validate( string $body, string $css, TemplateKind $kind ): array {
		$errors    = array_map( static fn ( TemplateSyntaxError $e ): array => $e->toArray(), $this->services->compiler()->validate( $body, $kind ) );
		$css_error = null;
		try {
			CssSanitizer::scope( $css, '.swc-tpl-validate' );
		} catch ( \InvalidArgumentException $e ) {
			$css_error = $e->getMessage();
		}
		return array(
			'valid'     => array() === $errors && null === $css_error,
			'errors'    => $errors,
			'css_error' => $css_error,
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function save( ?int $id, array $data ): Template {
		$existing = null === $id ? null : $this->services->templates()->find( $id );
		if ( null !== $id && null === $existing ) {
			throw new ValidationException( array( 'id' => 'not_found' ), 'Template not found.', 404 );
		}
		$errors = array();
		$name   = trim( sanitize_text_field( (string) ( $data['name'] ?? $existing?->name ?? '' ) ) );
		if ( '' === $name || mb_strlen( $name ) > 190 ) {
			$errors['name'] = 'invalid';
		}
		// The kind of an existing template cannot change: views reference it by kind.
		$kind = $existing?->kind ?? TemplateKind::tryFrom( (string) ( $data['kind'] ?? '' ) );
		$mode = EditorMode::tryFrom( (string) ( $data['editor_mode'] ?? $existing?->editorMode->value ?? 'html' ) );
		if ( null === $kind ) {
			throw new ValidationException( array( 'kind' => 'invalid' ) );
		}
		if ( null === $mode ) {
			$errors['editor_mode'] = 'invalid';
		}
		$body   = str_replace( "\r\n", "\n", (string) ( $data['body'] ?? $existing?->body ?? '' ) );
		$css    = str_replace( "\r\n", "\n", (string) ( $data['css'] ?? $existing?->css ?? '' ) );
		$schema = array_key_exists( 'visual_schema', $data ) ? $data['visual_schema'] : $existing?->visualSchema;
		if ( null !== $schema && ( ! is_array( $schema ) || strlen( (string) wp_json_encode( $schema ) ) > self::MAX_SCHEMA_BYTES ) ) {
			$errors['visual_schema'] = 'invalid';
		}
		if ( EditorMode::Html === $mode ) {
			$schema = null;
		}
		$validation = $this->validate( $body, $css, $kind );
		if ( ! $validation['valid'] ) {
			$errors['body'] = 'invalid_template';
		}
		if ( isset( $errors['body'] ) ) {
			throw new TemplateSaveException( $errors, $validation );
		}
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}
		$id = $this->services->templates()->save( $id, $name, $kind, $mode, $body, $css, $schema, $existing?->presetKey ?? ( isset( $data['preset_key'] ) ? sanitize_key( (string) $data['preset_key'] ) : null ) );
		$this->services->audit()->record( null === $existing ? 'template_created' : 'template_updated', 'template', $id );
		return $this->services->templates()->find( $id );
	}

	public function delete( int $id ): void {
		if ( null === $this->services->templates()->find( $id ) ) {
			throw new ValidationException( array( 'id' => 'not_found' ), 'Template not found.', 404 );
		}
		if ( array() !== $this->services->views()->ids_using_template( $id ) ) {
			throw new ValidationException( array( 'id' => 'in_use' ), 'The template is used by a view.', 409 );
		}
		$this->services->templates()->delete( $id );
		$this->services->audit()->record( 'template_deleted', 'template', $id );
	}

	/**
	 * Creates editable copies (container, event, detail) of a preset.
	 *
	 * @return list<Template>
	 */
	public function install_preset( string $key ): array {
		$library = $this->services->presets();
		if ( ! in_array( $key, $library->keys(), true ) ) {
			throw new ValidationException( array( 'key' => 'unknown_preset' ), 'Unknown preset.', 404 );
		}
		$meta    = $library->meta( $key );
		$created = array();
		foreach ( TemplateKind::cases() as $kind ) {
			$id        = $this->services->templates()->save(
				null,
				$meta['name'] . ' – ' . self::kind_label( $kind ),
				$kind,
				EditorMode::Html,
				(string) $library->body( $key, $kind ),
				$library->css( $key, $kind ),
				null,
				$key
			);
			$created[] = $this->services->templates()->find( $id );
		}
		return $created;
	}

	/** Installs the presets on a fresh installation. */
	public function seed_presets(): void {
		if ( $this->services->templates()->count() > 0 ) {
			return;
		}
		foreach ( $this->services->presets()->keys() as $key ) {
			$this->install_preset( $key );
		}
	}

	/**
	 * Safe preview of unsaved templates with the fixed datasets (no provider requests).
	 *
	 * @param array<string, mixed> $data
	 * @return array{html: string, css: object, errors: array<string, mixed>|object}
	 */
	public function preview( array $data ): array {
		$mode   = DisplayMode::tryFrom( (string) ( $data['mode'] ?? 'list' ) ) ?? DisplayMode::List;
		$preset = PresetLibrary::defaultFor( $mode );
		$parts  = array();
		$css    = array();
		$scopes = array();
		$errors = array();
		foreach ( TemplateKind::cases() as $kind ) {
			$body     = isset( $data[ $kind->value ]['body'] ) ? (string) $data[ $kind->value ]['body'] : (string) $this->services->presets()->body( $preset, $kind );
			$style    = isset( $data[ $kind->value ]['css'] ) ? (string) $data[ $kind->value ]['css'] : $this->services->presets()->css( $preset, $kind );
			$scope    = 'swc-preview-' . $kind->value;
			$scopes[] = $scope;
			try {
				$parts[ $kind->value ] = $this->services->compiler()->compile( $body, $kind->value );
				$css[ $scope ]         = '' === trim( $style ) ? '' : CssSanitizer::scope( $style, '.' . $scope );
			} catch ( TemplateValidationException $e ) {
				$errors[ $kind->value ] = array_map( static fn ( TemplateSyntaxError $err ): array => $err->toArray(), $e->errors );
			} catch ( \InvalidArgumentException $e ) {
				$errors[ $kind->value ] = array(
					array(
						'code'    => 'unsafe_css',
						'message' => $e->getMessage(),
					),
				);
			}
		}
		if ( array() !== $errors ) {
			return array(
				'html'   => '',
				'css'    => (object) array(),
				'errors' => $errors,
			);
		}
		$urls     = new PreviewUrlGenerator( SW_CALENDAR_URL . 'assets/img/preview.png' );
		$renderer = new PreviewRenderer( $this->services->dates(), $urls, $this->services->sanitizer(), $this->services->labels(), (int) get_option( 'start_of_week', 1 ) );
		$tz       = new \DateTimeZone( Settings::site_timezone() );
		$result   = $renderer->render( $parts['container'], $parts['event'], $parts['detail'], $mode, $this->services->clock()->now(), $tz, $scopes, $css );
		return array(
			'html'   => $result['html'],
			'css'    => (object) array_filter( $result['css'] ),
			'errors' => (object) array(),
		);
	}

	public static function kind_label( TemplateKind $kind ): string {
		return match ( $kind ) {
			TemplateKind::Container => __( 'Container', 'sw-cloudcalendar' ),
			TemplateKind::Event     => __( 'Event', 'sw-cloudcalendar' ),
			TemplateKind::Detail    => __( 'Detail', 'sw-cloudcalendar' ),
		};
	}
}
