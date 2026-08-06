<?php
/**
 * Multi-line text field type.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * A plain multi-line text area.
 *
 * Separate from Input because a textarea holds its value as content rather
 * than in an attribute, which changes both the markup and the escaping.
 */
final class Textarea extends FieldType {

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array( 'textarea' => __( 'Text Area', 'wp-custom-meta-box' ) );
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
			'textarea' => 'dashicons-editor-alignleft',
		);

		return $icons[ $type ] ?? 'dashicons-editor-alignleft';
	}

	/**
	 * Render the text area.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$attributes = $this->base_attributes( $field, $input_name, $input_id ) + array(
			'rows' => (string) $this->setting( $field, 'rows', '4' ),
		);

		unset( $attributes['pattern'] );

		printf(
			'<textarea %s>%s</textarea>',
			$this->attributes( $attributes ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.
			esc_textarea( is_scalar( $value ) ? (string) $value : '' )
		);
	}

	/**
	 * Clean a submitted value, preserving line breaks.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return string
	 */
	public function sanitize( mixed $value, array $field ): string {
		return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
	}

	/**
	 * Optionally convert line breaks to paragraphs on read.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		return match ( (string) $this->setting( $field, 'new_lines', 'none' ) ) {
			'wpautop' => wpautop( $value ),
			'br'      => nl2br( $value ),
			default   => $value,
		};
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		return array(
			'rows'               => array(
				'label' => __( 'Rows', 'wp-custom-meta-box' ),
				'type'  => 'number',
			),
			'maxlength'          => array(
				'label' => __( 'Maximum characters', 'wp-custom-meta-box' ),
				'type'  => 'number',
			),
			'new_lines'          => array(
				'label'   => __( 'New lines', 'wp-custom-meta-box' ),
				'type'    => 'select',
				'choices' => array(
					'none'    => __( 'Return unchanged', 'wp-custom-meta-box' ),
					'wpautop' => __( 'Add paragraphs', 'wp-custom-meta-box' ),
					'br'      => __( 'Add line breaks', 'wp-custom-meta-box' ),
				),
			),
			'validation_message' => array(
				'label' => __( 'Validation message', 'wp-custom-meta-box' ),
				'type'  => 'text',
			),
		);
	}
}
