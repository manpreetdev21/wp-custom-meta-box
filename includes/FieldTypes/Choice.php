<?php
/**
 * Choice field types.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Every field that picks from a fixed list of options.
 *
 * Select, checkbox, radio, toggle, button group, rating, country and state
 * all answer the same question and differ only in how the options are drawn
 * and where the list comes from. Sanitizing is the same for all of them and
 * is the important part: a submitted value is kept only if it is one of the
 * options actually offered, so a hand-built request cannot store a value the
 * form never contained.
 */
final class Choice extends FieldType {

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'select'       => __( 'Select', 'wp-custom-meta-box' ),
			'checkbox'     => __( 'Checkbox', 'wp-custom-meta-box' ),
			'radio'        => __( 'Radio', 'wp-custom-meta-box' ),
			'toggle'       => __( 'Toggle', 'wp-custom-meta-box' ),
			'button_group' => __( 'Button Group', 'wp-custom-meta-box' ),
			'rating'       => __( 'Rating', 'wp-custom-meta-box' ),
			'country'      => __( 'Country', 'wp-custom-meta-box' ),
			'state'        => __( 'State / Region', 'wp-custom-meta-box' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Choice', 'wp-custom-meta-box' );
	}

	/**
	 * The Dashicon shown beside a field of this type.
	 *
	 * @param string $type The specific type.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'button_group' => 'dashicons-grid-view',
			'checkbox'     => 'dashicons-yes-alt',
			'country'      => 'dashicons-admin-site',
			'radio'        => 'dashicons-marker',
			'rating'       => 'dashicons-star-filled',
			'select'       => 'dashicons-menu-alt',
			'state'        => 'dashicons-location',
			'toggle'       => 'dashicons-controls-play',
		);

		return $icons[ $type ] ?? 'dashicons-menu-alt';
	}

	/**
	 * Render the control.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$type     = (string) ( $field['type'] ?? 'select' );
		$choices  = $this->choices( $field );
		$multiple = $this->is_multiple( $field );
		$selected = $this->as_list( $value );

		if ( 'toggle' === $type ) {
			$this->render_toggle( $field, $selected, $input_name, $input_id );
			return;
		}

		if ( 'select' === $type ) {
			$this->render_select( $field, $choices, $selected, $input_name, $input_id, $multiple );
			return;
		}

		$this->render_options( $field, $type, $choices, $selected, $input_name, $input_id, $multiple );
	}

	/**
	 * Render a select element.
	 *
	 * @param array<string, mixed>  $field      Field definition.
	 * @param array<string, string> $choices    Available options.
	 * @param array<int, string>    $selected   Selected values.
	 * @param string                $input_name Input name.
	 * @param string                $input_id   Input id.
	 * @param bool                  $multiple   Whether multiple values are allowed.
	 */
	private function render_select( array $field, array $choices, array $selected, string $input_name, string $input_id, bool $multiple ): void {
		$attributes = array(
			'name'     => $multiple ? $input_name . '[]' : $input_name,
			'id'       => $input_id,
			'class'    => 'wpcmb-input',
			'required' => ! empty( $field['required'] ),
			'multiple' => $multiple,
			'size'     => $multiple ? '6' : '',
		);

		printf( '<select %s>', $this->attributes( $attributes ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.

		if ( ! $multiple ) {
			printf(
				'<option value="">%s</option>',
				esc_html( (string) $this->setting( $field, 'empty_label', __( '— Select —', 'wp-custom-meta-box' ) ) )
			);
		}

		foreach ( $choices as $option => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $option ),
				in_array( (string) $option, $selected, true ) ? ' selected' : '',
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Render a list of checkboxes or radios, including the button group and
	 * rating variants, which are the same inputs styled differently.
	 *
	 * @param array<string, mixed>  $field      Field definition.
	 * @param string                $type       Type name.
	 * @param array<string, string> $choices    Available options.
	 * @param array<int, string>    $selected   Selected values.
	 * @param string                $input_name Input name.
	 * @param string                $input_id   Input id.
	 * @param bool                  $multiple   Whether multiple values are allowed.
	 */
	private function render_options( array $field, string $type, array $choices, array $selected, string $input_name, string $input_id, bool $multiple ): void {
		$control = 'checkbox' === $type ? 'checkbox' : 'radio';
		$index   = 0;

		printf(
			'<div class="wpcmb-choices wpcmb-choices--%s" role="group" aria-labelledby="%s-label">',
			esc_attr( $type ),
			esc_attr( $input_id )
		);

		// An unchecked checkbox list posts nothing at all, which is
		// indistinguishable from "the field was not in the form". This tells
		// the save handler the field was present and deliberately cleared.
		if ( 'checkbox' === $control ) {
			printf( '<input type="hidden" name="%s" value="" />', esc_attr( $input_name ) );
		}

		foreach ( $choices as $option => $label ) {
			$option_id = $input_id . '-' . $index;
			++$index;

			printf(
				'<label class="wpcmb-choice" for="%1$s"><input type="%2$s" id="%1$s" name="%3$s" value="%4$s"%5$s%6$s /> <span>%7$s</span></label>',
				esc_attr( $option_id ),
				esc_attr( $control ),
				esc_attr( 'checkbox' === $control ? $input_name . '[]' : $input_name ),
				esc_attr( (string) $option ),
				in_array( (string) $option, $selected, true ) ? ' checked' : '',
				! empty( $field['required'] ) && 'radio' === $control ? ' required' : '',
				esc_html( $label )
			);
		}

		echo '</div>';
	}

	/**
	 * Render a single on/off toggle.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param array<int, string>   $selected   Selected values.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	private function render_toggle( array $field, array $selected, string $input_name, string $input_id ): void {
		printf(
			'<input type="hidden" name="%s" value="0" />
			<label class="wpcmb-toggle" for="%s"><input type="checkbox" id="%s" name="%s" value="1"%s /> <span>%s</span></label>',
			esc_attr( $input_name ),
			esc_attr( $input_id ),
			esc_attr( $input_id ),
			esc_attr( $input_name ),
			in_array( '1', $selected, true ) ? ' checked' : '',
			esc_html( (string) $this->setting( $field, 'toggle_label', '' ) )
		);
	}

	/**
	 * Keep only values that were actually offered.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function sanitize( mixed $value, array $field ): mixed {
		$type = (string) ( $field['type'] ?? 'select' );

		if ( 'toggle' === $type ) {
			return ! empty( $value ) && '0' !== $value;
		}

		$allowed  = array_map( 'strval', array_keys( $this->choices( $field ) ) );
		$multiple = $this->is_multiple( $field );
		$values   = array_values( array_intersect( $this->as_list( $value ), $allowed ) );

		if ( $multiple ) {
			return $values;
		}

		return $values[0] ?? '';
	}

	/**
	 * Optionally return the option label rather than its value.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		if ( 'toggle' === ( $field['type'] ?? '' ) ) {
			return (bool) $value;
		}

		if ( 'label' !== $this->setting( $field, 'return_format', 'value' ) ) {
			return $value;
		}

		$choices = $this->choices( $field );

		if ( is_array( $value ) ) {
			return array_map( static fn( $item ) => $choices[ (string) $item ] ?? $item, $value );
		}

		return $choices[ (string) $value ] ?? $value;
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		if ( 'toggle' === $type ) {
			return array(
				'toggle_label' => array(
					'label' => __( 'Toggle label', 'wp-custom-meta-box' ),
					'type'  => 'text',
				),
			);
		}

		$schema = array();

		if ( ! in_array( $type, array( 'country', 'state', 'rating' ), true ) ) {
			$schema['choices'] = array(
				'label' => __( 'Choices', 'wp-custom-meta-box' ),
				'type'  => 'choices',
				'help'  => __( 'One per line. Use value : Label to set a value separately from its label.', 'wp-custom-meta-box' ),
			);
		}

		if ( 'rating' === $type ) {
			$schema['max'] = array(
				'label' => __( 'Maximum rating', 'wp-custom-meta-box' ),
				'type'  => 'number',
			);
		}

		if ( in_array( $type, array( 'select', 'checkbox' ), true ) ) {
			$schema['multiple'] = array(
				'label' => __( 'Allow multiple', 'wp-custom-meta-box' ),
				'type'  => 'toggle',
			);
			$schema['min']      = array(
				'label' => __( 'Minimum selections', 'wp-custom-meta-box' ),
				'type'  => 'number',
			);
			$schema['max']      = array(
				'label' => __( 'Maximum selections', 'wp-custom-meta-box' ),
				'type'  => 'number',
			);
		}

		$schema['return_format'] = array(
			'label'   => __( 'Return format', 'wp-custom-meta-box' ),
			'type'    => 'select',
			'choices' => array(
				'value' => __( 'Value', 'wp-custom-meta-box' ),
				'label' => __( 'Label', 'wp-custom-meta-box' ),
			),
		);

		$schema['validation_message'] = array(
			'label' => __( 'Validation message', 'wp-custom-meta-box' ),
			'type'  => 'text',
		);

		return $schema;
	}

	/**
	 * Whether the field accepts more than one value.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	private function is_multiple( array $field ): bool {
		$type = (string) ( $field['type'] ?? '' );

		if ( 'checkbox' === $type ) {
			// A checkbox list is multi-value unless explicitly single.
			return '0' !== (string) $this->setting( $field, 'multiple', '1' );
		}

		return 'select' === $type && ! empty( $this->setting( $field, 'multiple', '' ) );
	}

	/**
	 * The options for a field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function choices( array $field ): array {
		$type = (string) ( $field['type'] ?? '' );

		$choices = match ( $type ) {
			'country' => $this->countries(),
			'state'   => $this->states(),
			'rating'  => $this->rating_scale( $field ),
			default   => $this->parse_choices( $this->setting( $field, 'choices', array() ) ),
		};

		/**
		 * Filters the options offered by a choice field.
		 *
		 * Also the hook to use for a country or region list that suits the
		 * site, since the built-in lists are deliberately short.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $choices Options keyed by value.
		 * @param array<string, mixed>  $field   Field definition.
		 */
		return (array) apply_filters( 'wpcmb/field/choices', $choices, $field );
	}

	/**
	 * Parse the editor's choice input into value => label pairs.
	 *
	 * Accepts either a list of lines or an already-keyed array, so a group
	 * registered in code can pass a map directly.
	 *
	 * @param mixed $raw Stored choices.
	 *
	 * @return array<string, string>
	 */
	private function parse_choices( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $raw );
			$raw   = is_array( $lines ) ? $lines : array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$choices = array();

		foreach ( $raw as $key => $line ) {
			if ( is_string( $key ) && ! is_array( $line ) ) {
				$choices[ $key ] = (string) $line;
				continue;
			}

			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			if ( str_contains( $line, ':' ) ) {
				list( $value, $label ) = array_map( 'trim', explode( ':', $line, 2 ) );
				$choices[ $value ]     = $label;
				continue;
			}

			$choices[ $line ] = $line;
		}

		return $choices;
	}

	/**
	 * A 1..n rating scale.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function rating_scale( array $field ): array {
		$max   = max( 2, min( 10, (int) $this->setting( $field, 'max', 5 ) ) );
		$scale = array();

		for ( $i = 1; $i <= $max; $i++ ) {
			$scale[ (string) $i ] = (string) $i;
		}

		return $scale;
	}

	/**
	 * A short country list, using WordPress's own locale data.
	 *
	 * Deliberately not a bundled ISO table: sites that need the full list
	 * have one already (WooCommerce, a locale plugin) and can supply it
	 * through `wpcmb/field/choices` rather than carrying a second copy here.
	 *
	 * @return array<string, string>
	 */
	private function countries(): array {
		$countries = array();

		foreach ( array( 'AU', 'CA', 'DE', 'ES', 'FR', 'IN', 'IT', 'JP', 'NL', 'NZ', 'SG', 'GB', 'US' ) as $code ) {
			$countries[ $code ] = $code;
		}

		if ( function_exists( 'WC' ) && method_exists( WC()->countries, 'get_countries' ) ) {
			$countries = WC()->countries->get_countries();
		}

		return $countries;
	}

	/**
	 * Regions, empty unless a source supplies them.
	 *
	 * @return array<string, string>
	 */
	private function states(): array {
		if ( function_exists( 'WC' ) && method_exists( WC()->countries, 'get_states' ) ) {
			$states = WC()->countries->get_states( (string) WC()->countries->get_base_country() );

			return is_array( $states ) ? $states : array();
		}

		return array();
	}

	/**
	 * Normalise a value to a list of strings.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array<int, string>
	 */
	private function as_list( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
		}

		if ( null === $value || '' === $value || false === $value ) {
			return array();
		}

		return array( is_bool( $value ) ? '1' : (string) $value );
	}
}
