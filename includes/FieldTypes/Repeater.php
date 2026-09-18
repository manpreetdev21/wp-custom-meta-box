<?php
/**
 * Repeater field type.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * A repeating set of sub fields.
 *
 * Rows are stored as one indexed array under the repeater's own name, the
 * same whole-value storage every other structured type uses: a repeater of
 * any size is one meta row and one read, and deleting it cannot leave orphan
 * sub values behind.
 *
 * Nesting needs no special handling. Sub fields render through the shared
 * renderer, which owns input naming, so a repeater inside a repeater inside a
 * group produces correctly nested names without this class knowing how deep
 * it is. The same is true on the way back in: sanitizing recurses through the
 * shared sanitizer, so a nested repeater's rows are cleaned by their own
 * types.
 *
 * ponytail: every row renders. A repeater with hundreds of rows makes a heavy
 * edit screen, and the fix is not pagination bolted onto a single form post —
 * a paginated repeater must save per row, which is a per-row AJAX save
 * endpoint and an optimistic-concurrency story. Rows past `collapse_after`
 * start collapsed, which keeps the screen usable well past the point where
 * the DOM alone would be a problem.
 */
class Repeater extends FieldType {

	/**
	 * Rows beyond this many start collapsed.
	 *
	 * Protected rather than private because Flexible extends this class: a
	 * flexible content field is a repeater whose rows differ in shape, and
	 * everything about rows — order, limits, chrome, reordering, nesting — is
	 * the same for both.
	 */
	protected const COLLAPSE_AFTER = 10;

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array( 'repeater' => __( 'Repeater', 'wp-custom-meta-box' ) );
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
			'repeater' => 'dashicons-controls-repeat',
		);

		return $icons[ $type ] ?? 'dashicons-controls-repeat';
	}

	/**
	 * Render the repeater.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$sub_fields = $this->sub_fields( $field );

		if ( array() === $sub_fields ) {
			printf(
				'<p class="wpcmb-field__note">%s</p>',
				esc_html__( 'This repeater has no sub fields yet.', 'wp-custom-meta-box' )
			);

			return;
		}

		$rows     = $this->rows( $value );
		$min      = (int) $this->setting( $field, 'min', 0 );
		$collapse = (int) $this->setting( $field, 'collapse_after', self::COLLAPSE_AFTER );

		// A repeater with a minimum starts with that many blank rows, so the
		// requirement is visible in the form rather than only on save.
		$blanks = max( 0, $min - count( $rows ) );

		for ( $i = 0; $i < $blanks; $i++ ) {
			$rows[] = array();
		}

		$attributes = $this->attributes(
			array(
				'data-wpcmb-repeater'  => '1',
				'data-wpcmb-field-key' => (string) ( $field['key'] ?? '' ),
				'data-wpcmb-name'      => $input_name,
				'data-wpcmb-id'        => $input_id,
				'data-wpcmb-min'       => (string) $min,
				'data-wpcmb-max'       => (string) (int) $this->setting( $field, 'max', 0 ),
				'data-wpcmb-row-label' => (string) $this->setting( $field, 'row_label', '' ),
				'data-wpcmb-csv'       => $this->setting( $field, 'csv', '' ) ? 'true' : 'false',
			)
		);

		printf(
			'<div class="wpcmb-repeater wpcmb-repeater--%s" %s>',
			esc_attr( (string) $this->setting( $field, 'layout', 'block' ) ),
			$attributes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.
		);

		echo '<div class="wpcmb-repeater__rows">';

		foreach ( $rows as $index => $row ) {
			$this->render_row( $field, $sub_fields, is_array( $row ) ? $row : array(), (int) $index, $input_name, $input_id, $index >= $collapse );
		}

		echo '</div>';

		printf(
			'<p class="wpcmb-repeater__actions">
				<button type="button" class="wpcmb-btn wpcmb-repeater__add">%s</button>
				<span class="wpcmb-repeater__csv"></span>
			</p></div>',
			esc_html( (string) $this->setting( $field, 'button_label', __( 'Add row', 'wp-custom-meta-box' ) ) )
		);
	}

	/**
	 * Render one row.
	 *
	 * Public so the AJAX endpoint can render a fresh row with the same
	 * markup. Rows are fetched from the server rather than cloned in the
	 * browser because cloning a row containing a `wp_editor()` instance
	 * produces a dead editor — the markup is only half of what that control
	 * is, and re-initialising it client-side means reimplementing what
	 * WordPress already does when it renders one.
	 *
	 * @param array<string, mixed>             $field      Repeater definition.
	 * @param array<int, array<string, mixed>> $sub_fields Sub field definitions.
	 * @param array<string, mixed>             $row        Row values.
	 * @param int                              $index      Row index.
	 * @param string                           $input_name Repeater input name.
	 * @param string                           $input_id   Repeater input id.
	 * @param bool                             $collapsed  Whether to start collapsed.
	 */
	public function render_row( array $field, array $sub_fields, array $row, int $index, string $input_name, string $input_id, bool $collapsed = false ): void {
		/**
		 * Filters the renderer used for a repeater's sub fields.
		 *
		 * @since 1.0.0
		 *
		 * @param callable|null        $renderer Callable( array $field, mixed $value, string $name, string $id ).
		 * @param array<string, mixed> $field    The repeater field.
		 */
		$renderer = apply_filters( 'wpcmb/field/sub_renderer', null, $field );

		$this->open_row( $index, $collapsed );
		$this->render_row_header( $this->row_title( $field, $index ), $collapsed );

		echo '<div class="wpcmb-repeater__fields">';

		foreach ( $sub_fields as $sub ) {
			if ( ! is_array( $sub ) || empty( $sub['name'] ) || ! is_callable( $renderer ) ) {
				continue;
			}

			$name = (string) $sub['name'];

			$renderer(
				$sub,
				$row[ $name ] ?? null,
				$input_name . '[' . $index . '][' . $name . ']',
				$input_id . '-' . $index . '-' . $name
			);
		}

		echo '</div></div>';
	}

	/**
	 * Open a row wrapper.
	 *
	 * @param int  $index     Row index.
	 * @param bool $collapsed Whether the row starts collapsed.
	 */
	protected function open_row( int $index, bool $collapsed ): void {
		printf(
			'<div class="wpcmb-repeater__row%s" data-wpcmb-index="%d">',
			$collapsed ? ' is-collapsed' : '',
			(int) $index
		);
	}

	/**
	 * Render the chrome above a row's fields.
	 *
	 * Shared with Flexible: the drag handle, collapse toggle, reorder,
	 * duplicate and remove controls behave identically whether a row has a
	 * fixed shape or a chosen layout, and the script binds to these classes.
	 *
	 * @param string $title     Row heading.
	 * @param bool   $collapsed Whether the row starts collapsed.
	 * @param string $badge     Optional badge shown before the title.
	 */
	protected function render_row_header( string $title, bool $collapsed, string $badge = '' ): void {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Titles and labels are escaped below; the icons are literal markup.
		printf(
			'<div class="wpcmb-repeater__header">
				<span class="wpcmb-repeater__handle" aria-hidden="true">%8$s</span>
				<button type="button" class="wpcmb-repeater__toggle" aria-expanded="%1$s">%9$s</button>
				%2$s
				<span class="wpcmb-repeater__title">%3$s</span>
				<span class="wpcmb-repeater__preview" aria-hidden="true"></span>
				<span class="wpcmb-repeater__buttons">
					<button type="button" class="wpcmb-repeater__move" data-wpcmb-delta="-1" aria-label="%4$s">%10$s</button>
					<button type="button" class="wpcmb-repeater__move" data-wpcmb-delta="1" aria-label="%5$s">%11$s</button>
					<button type="button" class="wpcmb-repeater__duplicate" aria-label="%6$s">%12$s</button>
					<button type="button" class="wpcmb-repeater__remove" aria-label="%7$s">%13$s</button>
				</span>
			</div>',
			$collapsed ? 'false' : 'true',
			'' !== $badge
				? '<span class="wpcmb-repeater__badge dashicons ' . esc_attr( $badge ) . '" aria-hidden="true"></span>'
				: '',
			esc_html( $title ),
			esc_attr__( 'Move up', 'wp-custom-meta-box' ),
			esc_attr__( 'Move down', 'wp-custom-meta-box' ),
			esc_attr__( 'Duplicate row', 'wp-custom-meta-box' ),
			esc_attr__( 'Remove row', 'wp-custom-meta-box' ),
			self::control_icon( 'grip' ),
			self::control_icon( 'chevron' ),
			self::control_icon( 'up' ),
			self::control_icon( 'down' ),
			self::control_icon( 'duplicate' ),
			self::control_icon( 'remove' )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * One of the row controls' icons, as inline SVG.
	 *
	 * Inline rather than Dashicons: a dashicon is a font glyph, so it arrives
	 * a frame late, sits on its own baseline and cannot be given a stroke
	 * weight that matches the rest of the field chrome. These are drawn at a
	 * single weight on a 16px grid and inherit `currentColor`, so hover and
	 * disabled states come from the button rather than from a second rule.
	 *
	 * The markup is a literal, never user input, so there is nothing here to
	 * escape.
	 *
	 * @param string $name Icon name.
	 */
	private static function control_icon( string $name ): string {
		$shapes = array(
			'grip'      => '<circle cx="6" cy="4" r="1.15"/><circle cx="6" cy="8" r="1.15"/><circle cx="6" cy="12" r="1.15"/>'
				. '<circle cx="10" cy="4" r="1.15"/><circle cx="10" cy="8" r="1.15"/><circle cx="10" cy="12" r="1.15"/>',
			'chevron'   => '<path d="M4.5 6.25 8 9.75l3.5-3.5"/>',
			'up'        => '<path d="M8 12.5V4M4.5 7.5 8 4l3.5 3.5"/>',
			'down'      => '<path d="M8 3.5V12M4.5 8.5 8 12l3.5-3.5"/>',
			'duplicate' => '<rect x="5.75" y="5.75" width="7.75" height="7.75" rx="1.75"/>'
				. '<path d="M10.25 5.75v-1.5a1.75 1.75 0 0 0-1.75-1.75h-4a1.75 1.75 0 0 0-1.75 1.75v4a1.75 1.75 0 0 0 1.75 1.75h1.5"/>',
			'remove'    => '<path d="M4.5 4.5 11.5 11.5M11.5 4.5 4.5 11.5"/>',
		);

		if ( ! isset( $shapes[ $name ] ) ) {
			return '';
		}

		// The dots are solid; everything else is a stroked outline.
		$fill = 'grip' === $name ? 'fill="currentColor" stroke="none"' : 'fill="none"';

		return '<svg class="wpcmb-svg" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"'
			. ' ' . $fill . ' stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'
			. $shapes[ $name ]
			. '</svg>';
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
		$sub_fields = $this->sub_fields( $field );
		$max        = (int) $this->setting( $field, 'max', 0 );

		/**
		 * Filters the sanitizer used for a repeater's sub values.
		 *
		 * @since 1.0.0
		 *
		 * @param callable|null        $sanitizer Callable( mixed $value, array $field ).
		 * @param array<string, mixed> $field     The repeater field.
		 */
		$sanitizer = apply_filters( 'wpcmb/field/sub_sanitizer', null, $field );

		$clean = array();

		foreach ( $this->rows( $value ) as $row ) {
			if ( $max > 0 && count( $clean ) >= $max ) {
				break;
			}

			$row  = is_array( $row ) ? $row : array();
			$next = array();

			foreach ( $sub_fields as $sub ) {
				if ( ! is_array( $sub ) || empty( $sub['name'] ) ) {
					continue;
				}

				$name = (string) $sub['name'];

				// A sub field absent from the row is stored as null rather
				// than skipped, so every row has the same shape and a
				// template can index into it without checking first.
				$next[ $name ] = is_callable( $sanitizer )
					? $sanitizer( $row[ $name ] ?? null, $sub )
					: ( $row[ $name ] ?? null );
			}

			$clean[] = $next;
		}

		return $clean;
	}

	/**
	 * Format stored rows for templates.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function format( mixed $value, array $field ): array {
		$sub_fields = $this->sub_fields( $field );

		/**
		 * Filters the formatter used for a repeater's sub values.
		 *
		 * @since 1.0.0
		 *
		 * @param callable|null        $formatter Callable( mixed $value, array $field ).
		 * @param array<string, mixed> $field     The repeater field.
		 */
		$formatter = apply_filters( 'wpcmb/field/sub_formatter', null, $field );

		$rows = array();

		foreach ( $this->rows( $value ) as $row ) {
			$row       = is_array( $row ) ? $row : array();
			$formatted = array();

			foreach ( $sub_fields as $sub ) {
				if ( ! is_array( $sub ) || empty( $sub['name'] ) ) {
					continue;
				}

				$name               = (string) $sub['name'];
				$formatted[ $name ] = is_callable( $formatter )
					? $formatter( $row[ $name ] ?? null, $sub )
					: ( $row[ $name ] ?? null );
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
			'layout'         => array(
				'label'   => __( 'Layout', 'wp-custom-meta-box' ),
				'type'    => 'select',
				'choices' => array(
					'block' => __( 'Block', 'wp-custom-meta-box' ),
					'table' => __( 'Table', 'wp-custom-meta-box' ),
				),
			),
			'button_label'   => array(
				'label' => __( 'Add row button label', 'wp-custom-meta-box' ),
				'type'  => 'text',
			),
			'row_label'      => array(
				'label' => __( 'Row label', 'wp-custom-meta-box' ),
				'type'  => 'text',
				'help'  => __( 'Use {index} for the row number. Defaults to "Row 1", "Row 2".', 'wp-custom-meta-box' ),
			),
			'collapse_after' => array(
				'label' => __( 'Collapse rows after', 'wp-custom-meta-box' ),
				'type'  => 'number',
				'help'  => __( 'Rows past this position start collapsed.', 'wp-custom-meta-box' ),
			),
			'csv'            => array(
				'label' => __( 'Show CSV import and export', 'wp-custom-meta-box' ),
				'type'  => 'toggle',
			),
		);
	}

	/**
	 * The repeater's sub field definitions.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sub_fields( array $field ): array {
		return is_array( $field['sub_fields'] ?? null ) ? array_values( $field['sub_fields'] ) : array();
	}

	/**
	 * Normalise a stored or submitted value to a list of rows.
	 *
	 * Submitted rows arrive keyed by the index they had in the browser, which
	 * has gaps once rows have been removed. Sorting by that key and then
	 * discarding it is what preserves the order the user saw.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array<int, mixed>
	 */
	protected function rows( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = $value;
		ksort( $rows, SORT_NUMERIC );

		return array_values( $rows );
	}

	/**
	 * The heading shown on a row.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param int                  $index Row index.
	 */
	protected function row_title( array $field, int $index ): string {
		$label = (string) $this->setting( $field, 'row_label', '' );

		if ( '' === $label ) {
			/* translators: %d: row number. */
			return sprintf( __( 'Row %d', 'wp-custom-meta-box' ), $index + 1 );
		}

		return str_replace( '{index}', (string) ( $index + 1 ), $label );
	}
}
