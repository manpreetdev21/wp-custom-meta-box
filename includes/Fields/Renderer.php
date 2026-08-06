<?php
/**
 * Field rendering.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

use WPCMB\Abstracts\Module;
use WPCMB\FieldTypes\Structure;

defined( 'ABSPATH' ) || exit;

/**
 * Draws fields and the wrapper around them.
 *
 * The wrapper owns the label, the instructions, the error slot, the width and
 * the conditional-logic data the script reads. Field types own only their
 * control, so adding a type never means re-implementing any of that — and
 * conditional logic, validation display and layout behave identically for
 * every type, including third-party ones.
 *
 * Also the single naming authority: input names are built here and nowhere
 * else, so a group inside a repeater row nests correctly without each type
 * knowing how deep it is.
 */
final class Renderer extends Module {

	/**
	 * The request key every field value posts under.
	 */
	public const INPUT_PREFIX = 'wpcmb_values';

	/**
	 * Validation errors to display, keyed by field name.
	 *
	 * @var array<string, string>
	 */
	private array $errors = array();

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		// Composite types recurse through this renderer rather than
		// resolving one themselves, so nesting keeps one naming scheme.
		add_filter( 'wpcmb/field/sub_renderer', array( $this, 'sub_renderer' ) );
		add_filter( 'wpcmb/field/sub_sanitizer', array( $this, 'sub_sanitizer' ) );
		add_filter( 'wpcmb/field/sub_formatter', array( $this, 'sub_formatter' ) );
	}

	/**
	 * Supply this renderer's field method to composite types.
	 *
	 * @return callable
	 */
	public function sub_renderer(): callable {
		return array( $this, 'field' );
	}

	/**
	 * Supply this renderer's sanitizer to composite types.
	 *
	 * @return callable
	 */
	public function sub_sanitizer(): callable {
		return array( $this, 'sanitize' );
	}

	/**
	 * Supply this renderer's formatter to composite types.
	 *
	 * @return callable
	 */
	public function sub_formatter(): callable {
		return array( $this, 'format' );
	}

	/**
	 * Format a stored value using its type's formatter.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		return $this->container->get( Registry::class )
			->get( (string) ( $field['type'] ?? 'text' ) )
			->format( $value, $field );
	}

	/**
	 * Set the validation errors to display on the next render.
	 *
	 * @param array<string, string> $errors Errors keyed by field name.
	 */
	public function set_errors( array $errors ): void {
		$this->errors = $errors;
	}

	/**
	 * Render every field in a group.
	 *
	 * @param FieldGroup $group  Field group.
	 * @param ObjectRef  $ref    Object whose values to show.
	 * @param string     $prefix Input name prefix.
	 */
	public function group( FieldGroup $group, ObjectRef $ref, string $prefix = self::INPUT_PREFIX ): void {
		$values = $this->container->get( Values::class );

		printf(
			'<div class="wpcmb-group-fields wpcmb-labels--%s" data-wpcmb-group="%s">',
			esc_attr( (string) ( $group->settings['label_placement'] ?? 'top' ) ),
			esc_attr( $group->key )
		);

		if ( '' !== (string) ( $group->settings['description'] ?? '' ) ) {
			printf(
				'<p class="wpcmb-group-description">%s</p>',
				esc_html( (string) $group->settings['description'] )
			);
		}

		foreach ( $group->fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}

			$name = (string) $field['name'];

			$this->field(
				$field,
				$this->stores_value( $field ) ? $values->get( $name, $ref, false ) : null,
				$prefix . '[' . $name . ']',
				'wpcmb-' . $group->key . '-' . $name
			);
		}

		echo '</div>';
	}

	/**
	 * Render one field, wrapper and all.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function field( array $field, mixed $value, string $input_name, string $input_id ): void {
		$type      = (string) ( $field['type'] ?? 'text' );
		$name      = (string) ( $field['name'] ?? '' );
		$handler   = $this->container->get( Registry::class )->get( $type );
		$width     = (string) ( $field['wrapper']['width'] ?? '' );
		$error     = $this->errors[ $name ] ?? '';
		$is_marker = in_array( $type, array( 'tab', 'accordion' ), true );

		$attributes = array(
			'class'                  => trim( 'wpcmb-field wpcmb-field--' . sanitize_html_class( $type ) . ' ' . (string) ( $field['wrapper']['class'] ?? '' ) ),
			'data-wpcmb-key'         => (string) ( $field['key'] ?? '' ),
			'data-wpcmb-name'        => $name,
			'data-wpcmb-type'        => $type,
			'style'                  => '' !== $width ? '--wpcmb-width:' . (int) $width . '%' : '',
			'data-wpcmb-conditional' => $this->conditional_attribute( $field ),
		);

		if ( '' !== (string) ( $field['wrapper']['id'] ?? '' ) ) {
			$attributes['id'] = (string) $field['wrapper']['id'];
		}

		printf( '<div %s>', $this->attributes( $attributes ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.

		if ( ! $is_marker && '' !== (string) ( $field['label'] ?? '' ) ) {
			printf(
				'<div class="wpcmb-field__label"><label id="%s-label" for="%s">%s%s</label></div>',
				esc_attr( $input_id ),
				esc_attr( $input_id ),
				esc_html( (string) $field['label'] ),
				! empty( $field['required'] )
					? ' <abbr class="wpcmb-required" title="' . esc_attr__( 'Required', 'wp-custom-meta-box' ) . '">*</abbr>'
					: ''
			);
		}

		echo '<div class="wpcmb-field__control">';

		$handler->render( $field, $value, $input_name, $input_id );

		if ( '' !== (string) ( $field['instructions'] ?? '' ) ) {
			printf(
				'<p class="wpcmb-field__instructions">%s</p>',
				wp_kses_post( (string) $field['instructions'] )
			);
		}

		printf(
			'<p class="wpcmb-field__error" role="alert"%s>%s</p>',
			'' === $error ? ' hidden' : '',
			esc_html( $error )
		);

		echo '</div></div>';
	}

	/**
	 * Clean a submitted value using its type's sanitizer.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function sanitize( mixed $value, array $field ): mixed {
		return $this->container->get( Registry::class )
			->get( (string) ( $field['type'] ?? 'text' ) )
			->sanitize( $value, $field );
	}

	/**
	 * Whether a field has a value to load and store.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	public function stores_value( array $field ): bool {
		$type    = (string) ( $field['type'] ?? '' );
		$handler = $this->container->get( Registry::class )->get( $type );

		if ( $handler instanceof Structure ) {
			return Structure::stores_value( $field );
		}

		return $handler->is_data();
	}

	/**
	 * The conditional logic the script needs, as a JSON attribute.
	 *
	 * Empty when the field has no logic, so the script can skip it entirely
	 * rather than parsing an empty rule set for every field on the screen.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	private function conditional_attribute( array $field ): string {
		$conditional = $field['conditional'] ?? array();

		if ( ! is_array( $conditional ) || empty( $conditional['rules'] ) ) {
			return '';
		}

		return (string) wp_json_encode( $conditional );
	}

	/**
	 * Build an attribute string, escaping every name and value.
	 *
	 * @param array<string, mixed> $attributes Attribute map.
	 */
	private function attributes( array $attributes ): string {
		$parts = array();

		foreach ( $attributes as $attribute => $value ) {
			if ( '' === $value || false === $value || null === $value ) {
				continue;
			}

			$parts[] = sprintf( '%s="%s"', esc_attr( $attribute ), esc_attr( (string) $value ) );
		}

		return implode( ' ', $parts );
	}
}
