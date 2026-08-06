<?php
/**
 * Flexible content field type.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Rows whose shape is chosen per row from a set of layouts.
 *
 * This extends Repeater rather than reimplementing it because that is what it
 * is: a repeater whose rows differ in shape. Order, gaps, limits, nesting,
 * row chrome, reordering and the server-rendered new row all behave
 * identically and are inherited rather than written twice.
 *
 * A row records which layout produced it, so a layout can be renamed, its
 * fields reordered, or another added without touching stored rows. A row
 * whose layout no longer exists is dropped on save rather than silently
 * re-interpreted as a different layout, which would scramble its values.
 */
final class Flexible extends Repeater {

	/**
	 * The key inside each row recording its layout.
	 *
	 * Prefixed with an underscore so it cannot collide with a sub field
	 * name: field names are sanitize_key()'d, which strips leading
	 * underscores from nothing but does not produce them.
	 */
	public const LAYOUT_KEY = '_layout';

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array( 'flexible_content' => __( 'Flexible Content', 'wp-custom-meta-box' ) );
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
			'flexible_content' => 'dashicons-layout',
		);

		return $icons[ $type ] ?? 'dashicons-layout';
	}

	/**
	 * Render the field.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$layouts = $this->layouts( $field );

		if ( array() === $layouts ) {
			printf(
				'<p class="wpcmb-field__note">%s</p>',
				esc_html__( 'This field has no layouts yet.', 'wp-custom-meta-box' )
			);

			return;
		}

		$rows     = $this->rows( $value );
		$collapse = (int) $this->setting( $field, 'collapse_after', self::COLLAPSE_AFTER );

		$attributes = $this->attributes(
			array(
				'data-wpcmb-repeater'  => '1',
				'data-wpcmb-flexible'  => '1',
				'data-wpcmb-field-key' => (string) ( $field['key'] ?? '' ),
				'data-wpcmb-name'      => $input_name,
				'data-wpcmb-id'        => $input_id,
				'data-wpcmb-min'       => (string) (int) $this->setting( $field, 'min', 0 ),
				'data-wpcmb-max'       => (string) (int) $this->setting( $field, 'max', 0 ),
				'data-wpcmb-csv'       => 'false',
				'data-wpcmb-layouts'   => (string) wp_json_encode( $this->layout_menu( $layouts ) ),
			)
		);

		printf(
			'<div class="wpcmb-repeater wpcmb-repeater--flexible" %s>',
			$attributes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.
		);

		echo '<div class="wpcmb-repeater__rows">';

		foreach ( $rows as $index => $row ) {
			$row    = is_array( $row ) ? $row : array();
			$layout = $layouts[ (string) ( $row[ self::LAYOUT_KEY ] ?? '' ) ] ?? null;

			// A row whose layout has been deleted still renders, so its
			// values are visible and recoverable rather than vanishing from
			// the screen before the user has seen them.
			if ( null === $layout ) {
				$this->render_orphan_row( $row, (int) $index, $input_name );
				continue;
			}

			$this->render_row( $field, $layout['sub_fields'], $row, (int) $index, $input_name, $input_id, $index >= $collapse );
		}

		echo '</div>';

		printf(
			'<p class="wpcmb-repeater__actions">
				<button type="button" class="button wpcmb-repeater__add">%s</button>
				<span class="wpcmb-repeater__csv"></span>
			</p></div>',
			esc_html( (string) $this->setting( $field, 'button_label', __( 'Add layout', 'wp-custom-meta-box' ) ) )
		);
	}

	/**
	 * Render one row of a known layout.
	 *
	 * The signature matches the parent's so the AJAX endpoint can call either
	 * without knowing which it has; here the sub fields passed in are the
	 * chosen layout's, and the layout name rides along in a hidden input.
	 *
	 * @param array<string, mixed>             $field      Field definition.
	 * @param array<int, array<string, mixed>> $sub_fields Layout sub fields.
	 * @param array<string, mixed>             $row        Row values.
	 * @param int                              $index      Row index.
	 * @param string                           $input_name Field input name.
	 * @param string                           $input_id   Field input id.
	 * @param bool                             $collapsed  Whether to start collapsed.
	 */
	public function render_row( array $field, array $sub_fields, array $row, int $index, string $input_name, string $input_id, bool $collapsed = false ): void {
		$layouts = $this->layouts( $field );
		$name    = (string) ( $row[ self::LAYOUT_KEY ] ?? '' );
		$layout  = $layouts[ $name ] ?? null;

		// The AJAX endpoint passes the chosen layout's sub fields; a row
		// being re-rendered from storage carries its layout name instead.
		if ( null === $layout ) {
			foreach ( $layouts as $candidate ) {
				if ( $candidate['sub_fields'] === $sub_fields ) {
					$layout = $candidate;
					$name   = $candidate['name'];
					break;
				}
			}
		}

		if ( null === $layout ) {
			return;
		}

		/** This filter is documented in includes/FieldTypes/Repeater.php */
		$renderer = apply_filters( 'wpcmb/field/sub_renderer', null, $field );

		$this->open_row( $index, $collapsed );
		$this->render_row_header( $layout['label'], $collapsed, $layout['icon'] );

		printf(
			'<input type="hidden" name="%s[%d][%s]" value="%s" />',
			esc_attr( $input_name ),
			(int) $index,
			esc_attr( self::LAYOUT_KEY ),
			esc_attr( $name )
		);

		echo '<div class="wpcmb-repeater__fields">';

		foreach ( $layout['sub_fields'] as $sub ) {
			if ( ! is_array( $sub ) || empty( $sub['name'] ) || ! is_callable( $renderer ) ) {
				continue;
			}

			$sub_name = (string) $sub['name'];

			$renderer(
				$sub,
				$row[ $sub_name ] ?? null,
				$input_name . '[' . $index . '][' . $sub_name . ']',
				$input_id . '-' . $index . '-' . $sub_name
			);
		}

		echo '</div></div>';
	}

	/**
	 * Render a row whose layout has been deleted.
	 *
	 * Its values are preserved in hidden inputs so that saving the screen
	 * does not destroy them, and the row is clearly marked so the editor can
	 * decide whether to remove it.
	 *
	 * @param array<string, mixed> $row        Row values.
	 * @param int                  $index      Row index.
	 * @param string               $input_name Field input name.
	 */
	private function render_orphan_row( array $row, int $index, string $input_name ): void {
		$this->open_row( $index, true );
		$this->render_row_header(
			sprintf(
				/* translators: %s: the layout name recorded on the row. */
				__( 'Unknown layout: %s', 'wp-custom-meta-box' ),
				(string) ( $row[ self::LAYOUT_KEY ] ?? '' )
			),
			true
		);

		echo '<div class="wpcmb-repeater__fields">';

		foreach ( $row as $key => $sub_value ) {
			if ( ! is_scalar( $sub_value ) ) {
				continue;
			}

			printf(
				'<input type="hidden" name="%s[%d][%s]" value="%s" />',
				esc_attr( $input_name ),
				(int) $index,
				esc_attr( (string) $key ),
				esc_attr( (string) $sub_value )
			);
		}

		printf(
			'<p class="wpcmb-field__note">%s</p>',
			esc_html__( 'The layout this row was built with no longer exists. Its values are kept until you remove the row.', 'wp-custom-meta-box' )
		);

		echo '</div></div>';
	}

	/**
	 * Clean submitted rows.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sanitize( mixed $value, array $field ): array {
		$layouts = $this->layouts( $field );
		$max     = (int) $this->setting( $field, 'max', 0 );

		/** This filter is documented in includes/FieldTypes/Repeater.php */
		$sanitizer = apply_filters( 'wpcmb/field/sub_sanitizer', null, $field );

		$clean = array();
		$used  = array();

		foreach ( $this->rows( $value ) as $row ) {
			if ( $max > 0 && count( $clean ) >= $max ) {
				break;
			}

			$row  = is_array( $row ) ? $row : array();
			$name = (string) ( $row[ self::LAYOUT_KEY ] ?? '' );

			// A row naming a layout that does not exist is dropped rather
			// than coerced into another one, which would scramble its values
			// into fields that were never meant to hold them.
			if ( ! isset( $layouts[ $name ] ) ) {
				continue;
			}

			$layout        = $layouts[ $name ];
			$used[ $name ] = ( $used[ $name ] ?? 0 ) + 1;
			$layout_max    = (int) ( $layout['max'] ?? 0 );

			if ( $layout_max > 0 && $used[ $name ] > $layout_max ) {
				continue;
			}

			$next = array( self::LAYOUT_KEY => $name );

			foreach ( $layout['sub_fields'] as $sub ) {
				if ( ! is_array( $sub ) || empty( $sub['name'] ) ) {
					continue;
				}

				$sub_name          = (string) $sub['name'];
				$next[ $sub_name ] = is_callable( $sanitizer )
					? $sanitizer( $row[ $sub_name ] ?? null, $sub )
					: ( $row[ $sub_name ] ?? null );
			}

			$clean[] = $next;
		}

		return $clean;
	}

	/**
	 * Format stored rows for templates.
	 *
	 * Each row keeps its layout name, because a template's whole job here is
	 * to switch on which layout a row is.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function format( mixed $value, array $field ): array {
		$layouts = $this->layouts( $field );

		/** This filter is documented in includes/FieldTypes/Repeater.php */
		$formatter = apply_filters( 'wpcmb/field/sub_formatter', null, $field );

		$rows = array();

		foreach ( $this->rows( $value ) as $row ) {
			$row  = is_array( $row ) ? $row : array();
			$name = (string) ( $row[ self::LAYOUT_KEY ] ?? '' );

			if ( ! isset( $layouts[ $name ] ) ) {
				continue;
			}

			$formatted = array( self::LAYOUT_KEY => $name );

			foreach ( $layouts[ $name ]['sub_fields'] as $sub ) {
				if ( ! is_array( $sub ) || empty( $sub['name'] ) ) {
					continue;
				}

				$sub_name               = (string) $sub['name'];
				$formatted[ $sub_name ] = is_callable( $formatter )
					? $formatter( $row[ $sub_name ] ?? null, $sub )
					: ( $row[ $sub_name ] ?? null );
			}

			$rows[] = $formatted;
		}

		return $rows;
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
			'min'            => array(
				'label' => __( 'Minimum rows', 'wp-custom-meta-box' ),
				'type'  => 'number',
			),
			'max'            => array(
				'label' => __( 'Maximum rows', 'wp-custom-meta-box' ),
				'type'  => 'number',
			),
			'button_label'   => array(
				'label' => __( 'Add button label', 'wp-custom-meta-box' ),
				'type'  => 'text',
			),
			'collapse_after' => array(
				'label' => __( 'Collapse rows after', 'wp-custom-meta-box' ),
				'type'  => 'number',
			),
		);
	}

	/**
	 * The field's layouts, keyed by layout name.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, array{name: string, label: string, icon: string, category: string, max: int, sub_fields: array<int, array<string, mixed>>}>
	 */
	public function layouts( array $field ): array {
		$raw     = is_array( $field['layouts'] ?? null ) ? $field['layouts'] : array();
		$layouts = array();

		foreach ( $raw as $layout ) {
			if ( ! is_array( $layout ) || empty( $layout['name'] ) ) {
				continue;
			}

			$name     = (string) $layout['name'];
			$settings = is_array( $layout['settings'] ?? null ) ? $layout['settings'] : array();

			$layouts[ $name ] = array(
				'name'       => $name,
				'label'      => '' !== (string) ( $layout['label'] ?? '' ) ? (string) $layout['label'] : $name,
				'icon'       => (string) ( $settings['icon'] ?? '' ),
				'category'   => (string) ( $settings['category'] ?? '' ),
				'max'        => (int) ( $settings['max'] ?? 0 ),
				'sub_fields' => is_array( $layout['sub_fields'] ?? null ) ? array_values( $layout['sub_fields'] ) : array(),
			);
		}

		return $layouts;
	}

	/**
	 * The layout picker's menu, grouped by category.
	 *
	 * @param array<string, array<string, mixed>> $layouts Layouts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function layout_menu( array $layouts ): array {
		$menu = array();

		foreach ( $layouts as $layout ) {
			$menu[] = array(
				'name'     => $layout['name'],
				'label'    => $layout['label'],
				'icon'     => $layout['icon'],
				'category' => '' !== $layout['category']
					? $layout['category']
					: __( 'Layouts', 'wp-custom-meta-box' ),
				'max'      => $layout['max'],
			);
		}

		return $menu;
	}
}
