<?php
/**
 * Structural field types.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Fields that organise a form rather than collect an answer.
 *
 * Message, tab and accordion draw something and store nothing — `is_data()`
 * is false for them, so they never create an empty meta row and are never
 * validated. Group is the exception: it stores its sub fields as one array,
 * which is why it shares this class rather than being structural too.
 */
final class Structure extends FieldType {

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'message'   => __( 'Message', 'wp-custom-meta-box' ),
			'tab'       => __( 'Tab', 'wp-custom-meta-box' ),
			'accordion' => __( 'Accordion', 'wp-custom-meta-box' ),
			'group'     => __( 'Group', 'wp-custom-meta-box' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Layout', 'wp-custom-meta-box' );
	}

	/**
	 * The Dashicon shown beside a field of this type.
	 *
	 * @param string $type The specific type.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'accordion' => 'dashicons-editor-insertmore',
			'group'     => 'dashicons-screenoptions',
			'message'   => 'dashicons-info',
			'tab'       => 'dashicons-index-card',
		);

		return $icons[ $type ] ?? 'dashicons-screenoptions';
	}

	/**
	 * Whether the type stores a value.
	 *
	 * Decided per instance is impossible here — the registry asks the class,
	 * not the field — so this returns true and the renderer consults
	 * stores_value() with the actual field.
	 */
	public function is_data(): bool {
		return true;
	}

	/**
	 * Whether a specific field of one of these types stores anything.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	public static function stores_value( array $field ): bool {
		return 'group' === ( $field['type'] ?? '' );
	}

	/**
	 * Render the structural element.
	 *
	 * Tabs and accordions are markers: the renderer walks the field list in
	 * order and the script uses these to slice it up, so a marker cannot
	 * "contain" fields and there is no nesting to get wrong.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$type  = (string) ( $field['type'] ?? '' );
		$label = (string) ( $field['label'] ?? '' );

		if ( 'message' === $type ) {
			printf(
				'<div class="wpcmb-message">%s</div>',
				wp_kses_post( (string) $this->setting( $field, 'message', '' ) )
			);

			return;
		}

		if ( 'tab' === $type ) {
			printf(
				'<span class="wpcmb-tab-marker" data-wpcmb-tab="%s" data-wpcmb-label="%s"></span>',
				esc_attr( (string) ( $field['key'] ?? '' ) ),
				esc_attr( $label )
			);

			return;
		}

		if ( 'accordion' === $type ) {
			printf(
				'<span class="wpcmb-accordion-marker" data-wpcmb-accordion="%s" data-wpcmb-label="%s" data-wpcmb-open="%s"></span>',
				esc_attr( (string) ( $field['key'] ?? '' ) ),
				esc_attr( $label ),
				esc_attr( $this->setting( $field, 'open', '' ) ? '1' : '0' )
			);

			return;
		}

		// A group renders its sub fields with names nested under its own, so
		// the whole group posts and stores as a single associative array.
		$this->render_group( $field, $value, $input_name, $input_id );
	}

	/**
	 * Render a group's sub fields.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	private function render_group( array $field, mixed $value, string $input_name, string $input_id ): void {
		$sub_fields = is_array( $field['sub_fields'] ?? null ) ? $field['sub_fields'] : array();
		$values     = is_array( $value ) ? $value : array();

		if ( array() === $sub_fields ) {
			printf(
				'<p class="wpcmb-field__note">%s</p>',
				esc_html__( 'This group has no fields yet.', 'wp-custom-meta-box' )
			);

			return;
		}

		echo '<div class="wpcmb-group">';

		/**
		 * Filters the renderer used for a group's sub fields.
		 *
		 * The renderer is passed in rather than resolved here so that nested
		 * structures — a group inside a repeater row — keep one renderer and
		 * one naming scheme all the way down.
		 *
		 * @since 1.0.0
		 *
		 * @param callable|null        $renderer Callable( array $field, mixed $value, string $name, string $id ).
		 * @param array<string, mixed> $field    The group field.
		 */
		$renderer = apply_filters( 'wpcmb/field/sub_renderer', null, $field );

		foreach ( $sub_fields as $sub ) {
			if ( ! is_array( $sub ) || empty( $sub['name'] ) ) {
				continue;
			}

			$name     = (string) $sub['name'];
			$sub_name = $input_name . '[' . $name . ']';
			$sub_id   = $input_id . '-' . $name;

			if ( is_callable( $renderer ) ) {
				$renderer( $sub, $values[ $name ] ?? null, $sub_name, $sub_id );
			}
		}

		echo '</div>';
	}

	/**
	 * Clean a group's sub values, discarding anything not declared.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function sanitize( mixed $value, array $field ): mixed {
		if ( ! self::stores_value( $field ) ) {
			return null;
		}

		$sub_fields = is_array( $field['sub_fields'] ?? null ) ? $field['sub_fields'] : array();
		$submitted  = is_array( $value ) ? $value : array();
		$clean      = array();

		/**
		 * Filters the sanitizer used for a group's sub values.
		 *
		 * @since 1.0.0
		 *
		 * @param callable|null        $sanitizer Callable( mixed $value, array $field ).
		 * @param array<string, mixed> $field     The group field.
		 */
		$sanitizer = apply_filters( 'wpcmb/field/sub_sanitizer', null, $field );

		foreach ( $sub_fields as $sub ) {
			if ( ! is_array( $sub ) || empty( $sub['name'] ) ) {
				continue;
			}

			$name           = (string) $sub['name'];
			$sub_value      = $submitted[ $name ] ?? null;
			$clean[ $name ] = is_callable( $sanitizer ) ? $sanitizer( $sub_value, $sub ) : $sub_value;
		}

		return $clean;
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		return match ( $type ) {
			'message'   => array(
				'message' => array(
					'label' => __( 'Message', 'wp-custom-meta-box' ),
					'type'  => 'textarea',
				),
			),
			'accordion' => array(
				'open' => array(
					'label' => __( 'Open by default', 'wp-custom-meta-box' ),
					'type'  => 'toggle',
				),
			),
			default     => array(),
		};
	}
}
