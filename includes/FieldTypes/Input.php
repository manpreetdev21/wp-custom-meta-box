<?php
/**
 * Single-line input field types.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Every field that is one `<input>` element.
 *
 * These types differ by an HTML type attribute, a sanitizer and sometimes a
 * pattern — not by behaviour. Browsers already supply the date picker, the
 * colour picker, the number spinner and the format validation for each of
 * them, so there is no picker library here and nothing to keep in sync with
 * a browser release.
 */
final class Input extends FieldType {

	/**
	 * Type name to the HTML input type it renders as.
	 */
	private const HTML_TYPES = array(
		'text'     => 'text',
		'password' => 'password',
		'number'   => 'number',
		'email'    => 'email',
		'url'      => 'url',
		'hidden'   => 'hidden',
		'range'    => 'range',
		'color'    => 'color',
		'date'     => 'date',
		'time'     => 'time',
		'datetime' => 'datetime-local',
		'phone'    => 'tel',
		'slug'     => 'text',
		'uuid'     => 'text',
		'currency' => 'number',
	);

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'text'     => __( 'Text', 'wp-custom-meta-box' ),
			'number'   => __( 'Number', 'wp-custom-meta-box' ),
			'email'    => __( 'Email', 'wp-custom-meta-box' ),
			'url'      => __( 'URL', 'wp-custom-meta-box' ),
			'password' => __( 'Password', 'wp-custom-meta-box' ),
			'hidden'   => __( 'Hidden', 'wp-custom-meta-box' ),
			'range'    => __( 'Range', 'wp-custom-meta-box' ),
			'color'    => __( 'Colour', 'wp-custom-meta-box' ),
			'date'     => __( 'Date', 'wp-custom-meta-box' ),
			'time'     => __( 'Time', 'wp-custom-meta-box' ),
			'datetime' => __( 'Date & Time', 'wp-custom-meta-box' ),
			'phone'    => __( 'Phone', 'wp-custom-meta-box' ),
			'slug'     => __( 'Slug', 'wp-custom-meta-box' ),
			'uuid'     => __( 'UUID', 'wp-custom-meta-box' ),
			'currency' => __( 'Currency', 'wp-custom-meta-box' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Basic', 'wp-custom-meta-box' );
	}

	/**
	 * The Dashicon shown beside a field of this type.
	 *
	 * @param string $type The specific type.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'color'    => 'dashicons-art',
			'currency' => 'dashicons-money-alt',
			'date'     => 'dashicons-calendar-alt',
			'datetime' => 'dashicons-calendar',
			'email'    => 'dashicons-email',
			'hidden'   => 'dashicons-hidden',
			'number'   => 'dashicons-calculator',
			'password' => 'dashicons-lock',
			'phone'    => 'dashicons-phone',
			'range'    => 'dashicons-leftright',
			'slug'     => 'dashicons-admin-links',
			'text'     => 'dashicons-edit',
			'time'     => 'dashicons-clock',
			'url'      => 'dashicons-admin-links',
			'uuid'     => 'dashicons-tag',
		);

		return $icons[ $type ] ?? 'dashicons-edit';
	}

	/**
	 * Render the input.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$type = (string) ( $field['type'] ?? 'text' );

		$attributes = $this->base_attributes( $field, $input_name, $input_id ) + array(
			'type'  => self::HTML_TYPES[ $type ] ?? 'text',
			'value' => is_scalar( $value ) ? (string) $value : '',
			'step'  => $this->step( $field, $type ),
			'min'   => $this->setting( $field, 'min' ),
			'max'   => $this->setting( $field, 'max' ),
		);

		if ( 'slug' === $type ) {
			$attributes['pattern']        = '[a-z0-9\-]+';
			$attributes['inputmode']      = 'latin';
			$attributes['spellcheck']     = 'false';
			$attributes['autocapitalize'] = 'none';
		}

		if ( 'uuid' === $type ) {
			$attributes['readonly']        = true;
			$attributes['data-wpcmb-uuid'] = '1';
			$attributes['value']           = '' !== $attributes['value'] ? $attributes['value'] : wp_generate_uuid4();
		}

		if ( 'color' === $type ) {
			$this->render_color( $attributes );

			return;
		}

		printf( '<input %s />', $this->attributes( $attributes ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.

		if ( 'range' === $type ) {
			printf(
				'<output class="wpcmb-range-value" for="%s">%s</output>',
				esc_attr( $input_id ),
				esc_html( (string) $attributes['value'] )
			);
		}
	}

	/**
	 * Render a colour as a swatch on one side and its hex code on the other.
	 *
	 * The text input is the one that submits, not the swatch. A native colour
	 * input cannot hold an empty value — browsers fall back to black — so if
	 * the swatch carried the name there would be no way to leave a colour
	 * unset, and every untouched field would save #000000.
	 *
	 * @param array<string, mixed> $attributes Attributes for the submitting input.
	 */
	private function render_color( array $attributes ): void {
		$value       = (string) $attributes['value'];
		$placeholder = (string) $attributes['placeholder'];

		$code                   = $attributes;
		$code['type']           = 'text';
		$code['class']          = 'wpcmb-input wpcmb-color__code';
		$code['pattern']        = '#([A-Fa-f0-9]{3}){1,2}';
		$code['placeholder']    = '' !== $placeholder ? $placeholder : '#000000';
		$code['maxlength']      = 7;
		$code['spellcheck']     = 'false';
		$code['autocapitalize'] = 'none';
		$code['autocomplete']   = 'off';

		// Meaningless on a text input, and `step` would block form submission.
		unset( $code['step'], $code['min'], $code['max'], $code['minlength'] );

		$swatch = array(
			'type'       => 'color',
			'class'      => 'wpcmb-color__swatch',
			'value'      => '' !== $value ? $value : '#000000',
			'tabindex'   => '-1',
			'aria-label' => __( 'Pick a colour', 'wp-custom-meta-box' ),
		);

		echo '<div class="wpcmb-color">';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.
		printf( '<input %s />', $this->attributes( $swatch ) );
		printf( '<input %s />', $this->attributes( $code ) );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Clean a submitted value.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function sanitize( mixed $value, array $field ): mixed {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		return match ( (string) ( $field['type'] ?? 'text' ) ) {
			'email'                     => sanitize_email( $value ),
			'url'                       => $this->url( $value ),
			'color'                     => (string) sanitize_hex_color( $value ),
			'slug'                      => sanitize_title( $value ),
			'uuid'                      => wp_is_uuid( $value ) ? $value : wp_generate_uuid4(),
			'number', 'range', 'currency' => $this->number( $value ),
			'date', 'time', 'datetime'  => $this->datetime( $value, (string) $field['type'] ),
			'password'                  => $value,
			default                     => sanitize_text_field( $value ),
		};
	}

	/**
	 * Format a stored value for templates.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		$type = (string) ( $field['type'] ?? 'text' );

		if ( in_array( $type, array( 'number', 'range', 'currency' ), true ) ) {
			return is_numeric( $value ) ? $this->number( (string) $value ) : $value;
		}

		$format = (string) $this->setting( $field, 'return_format' );

		if ( '' === $format || ! is_string( $value ) || '' === $value
			|| ! in_array( $type, array( 'date', 'time', 'datetime' ), true )
		) {
			return $value;
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? $value : wp_date( $format, $timestamp );
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		$schema = array();

		if ( in_array( $type, array( 'number', 'range', 'currency' ), true ) ) {
			$schema['min']  = array(
				'label' => __( 'Minimum', 'wp-custom-meta-box' ),
				'type'  => 'number',
			);
			$schema['max']  = array(
				'label' => __( 'Maximum', 'wp-custom-meta-box' ),
				'type'  => 'number',
			);
			$schema['step'] = array(
				'label' => __( 'Step', 'wp-custom-meta-box' ),
				'type'  => 'text',
			);
		}

		if ( in_array( $type, array( 'text', 'password', 'phone', 'slug' ), true ) ) {
			$schema['maxlength'] = array(
				'label' => __( 'Maximum characters', 'wp-custom-meta-box' ),
				'type'  => 'number',
			);
			$schema['minlength'] = array(
				'label' => __( 'Minimum characters', 'wp-custom-meta-box' ),
				'type'  => 'number',
			);
			$schema['pattern']   = array(
				'label' => __( 'Validation pattern', 'wp-custom-meta-box' ),
				'type'  => 'text',
			);
		}

		if ( in_array( $type, array( 'date', 'time', 'datetime' ), true ) ) {
			$schema['return_format'] = array(
				'label' => __( 'Return format', 'wp-custom-meta-box' ),
				'type'  => 'text',
				'help'  => __( 'A PHP date format. Leave empty to return the raw value.', 'wp-custom-meta-box' ),
			);
		}

		if ( array() !== $schema ) {
			$schema['validation_message'] = array(
				'label' => __( 'Validation message', 'wp-custom-meta-box' ),
				'type'  => 'text',
			);
		}

		return $schema;
	}

	/**
	 * The step attribute, defaulting to cents for currency.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param string               $type  Type name.
	 */
	private function step( array $field, string $type ): string {
		if ( ! in_array( $type, array( 'number', 'range', 'currency' ), true ) ) {
			return '';
		}

		return (string) $this->setting( $field, 'step', 'currency' === $type ? '0.01' : '' );
	}

	/**
	 * Clean a URL, or store nothing.
	 *
	 * WordPress prepends a scheme to anything without one, so free text
	 * such as "not a url" comes back as `http://not%20a%20url` — safe to
	 * print, but stored garbage that looks like a real value. Whitespace is
	 * the reliable tell: a URL never contains any once trimmed, while every
	 * shape worth keeping does not — relative paths, fragments, `mailto:`
	 * and `tel:` links, and internationalised domains all pass.
	 *
	 * @param string $value Submitted value.
	 */
	private function url( string $value ): string {
		$value = trim( $value );

		if ( '' === $value || 1 === preg_match( '/\s/', $value ) ) {
			return '';
		}

		return sanitize_url( $value );
	}

	/**
	 * Cast a numeric string, keeping integers as integers.
	 *
	 * @param string $value Numeric string.
	 *
	 * @return int|float|string
	 */
	private function number( string $value ): int|float|string {
		if ( ! is_numeric( $value ) ) {
			return '';
		}

		return floor( (float) $value ) === (float) $value && ! str_contains( $value, '.' )
			? (int) $value
			: (float) $value;
	}

	/**
	 * Keep a date, time or datetime only if the browser sent a real one.
	 *
	 * The native controls submit ISO strings; anything else came from a
	 * hand-built request and is discarded rather than stored as free text.
	 *
	 * @param string $value Submitted value.
	 * @param string $type  Type name.
	 */
	private function datetime( string $value, string $type ): string {
		$pattern = match ( $type ) {
			'date'     => '/^\d{4}-\d{2}-\d{2}$/',
			'time'     => '/^\d{2}:\d{2}(:\d{2})?$/',
			'datetime' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/',
			default    => '/^$/',
		};

		return 1 === preg_match( $pattern, $value ) ? $value : '';
	}
}
