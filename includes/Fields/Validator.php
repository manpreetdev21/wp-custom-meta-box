<?php
/**
 * Field validation.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Validates submitted values against their field definitions.
 *
 * Server-side validation is the only validation that counts: the browser's
 * copy exists to give fast feedback, not to decide what may be stored. Every
 * value that reaches storage from a form passes through here, including ones
 * whose inputs the browser marked valid.
 *
 * Fields hidden by conditional logic are skipped, so a required field the
 * user was never shown cannot block a save.
 */
final class Validator {

	/**
	 * Validate a set of values against a set of fields.
	 *
	 * @param array<string, array<string, mixed>> $fields Field definitions, keyed by name.
	 * @param array<string, mixed>                $values Submitted values, keyed by name.
	 *
	 * @return array<string, string> Error messages, keyed by field name. Empty when valid.
	 */
	public function validate( array $fields, array $values ): array {
		$errors = array();

		$lookup = static function ( string $key ) use ( $fields, $values ) {
			foreach ( $fields as $name => $field ) {
				if ( ( $field['key'] ?? '' ) === $key ) {
					return $values[ $name ] ?? null;
				}
			}

			return null;
		};

		foreach ( $fields as $name => $field ) {
			if ( ! Conditional::is_visible( $field, $lookup ) ) {
				continue;
			}

			$error = $this->validate_field( $field, $values[ $name ] ?? null );

			if ( '' !== $error ) {
				$errors[ $name ] = $error;
			}
		}

		return $errors;
	}

	/**
	 * Validate one value.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Submitted value.
	 *
	 * @return string Error message, or an empty string when valid.
	 */
	public function validate_field( array $field, mixed $value ): string {
		$settings = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
		$empty    = $this->is_empty( $value );

		if ( ! empty( $field['required'] ) && $empty ) {
			$error = $this->message(
				$settings,
				/* translators: %s: field label. */
				sprintf( __( '%s is required.', 'wp-custom-meta-box' ), $this->label( $field ) )
			);

			return $this->filter( $error, $field, $value );
		}

		// An optional field left blank has nothing else worth checking; the
		// format rules below would otherwise reject every empty optional field.
		if ( $empty ) {
			return $this->filter( '', $field, $value );
		}

		// First failure wins: reporting one problem at a time is clearer than
		// stacking "not a number" on top of "too long".
		$checks = array(
			$this->check_type( $field, $value ),
			$this->check_length( $settings, $field, $value ),
			$this->check_range( $settings, $field, $value ),
			$this->check_pattern( $settings, $field, $value ),
		);

		foreach ( $checks as $error ) {
			if ( '' !== $error ) {
				return $this->filter( $error, $field, $value );
			}
		}

		return $this->filter( '', $field, $value );
	}

	/**
	 * Format checks implied by the field type.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Submitted value.
	 */
	private function check_type( array $field, mixed $value ): string {
		$string = is_scalar( $value ) ? (string) $value : '';
		$type   = (string) ( $field['type'] ?? '' );

		if ( 'email' === $type && ! is_email( $string ) ) {
			return __( 'Enter a valid email address.', 'wp-custom-meta-box' );
		}

		if ( 'url' === $type && filter_var( $string, FILTER_VALIDATE_URL ) !== $string ) {
			return __( 'Enter a valid URL.', 'wp-custom-meta-box' );
		}

		if ( in_array( $type, array( 'number', 'range' ), true ) && ! is_numeric( $string ) ) {
			return __( 'Enter a number.', 'wp-custom-meta-box' );
		}

		return '';
	}

	/**
	 * Character-length checks.
	 *
	 * @param array<string, mixed> $settings Field settings.
	 * @param array<string, mixed> $field    Field definition.
	 * @param mixed                $value    Submitted value.
	 */
	private function check_length( array $settings, array $field, mixed $value ): string {
		if ( ! is_string( $value ) || in_array( (string) ( $field['type'] ?? '' ), array( 'number', 'range' ), true ) ) {
			return '';
		}

		$length = mb_strlen( $value );
		$max    = $settings['maxlength'] ?? '';

		if ( '' !== $max && $length > (int) $max ) {
			/* translators: %d: maximum number of characters. */
			return sprintf( __( 'Enter no more than %d characters.', 'wp-custom-meta-box' ), (int) $max );
		}

		$min = $settings['minlength'] ?? '';

		if ( '' !== $min && $length < (int) $min ) {
			/* translators: %d: minimum number of characters. */
			return sprintf( __( 'Enter at least %d characters.', 'wp-custom-meta-box' ), (int) $min );
		}

		return '';
	}

	/**
	 * Numeric range checks.
	 *
	 * Also applies to the number of selections in a multi-value field, which
	 * is what min and max mean for a checkbox list or a repeater.
	 *
	 * @param array<string, mixed> $settings Field settings.
	 * @param array<string, mixed> $field    Field definition.
	 * @param mixed                $value    Submitted value.
	 */
	private function check_range( array $settings, array $field, mixed $value ): string {
		$min = $settings['min'] ?? '';
		$max = $settings['max'] ?? '';

		if ( '' === $min && '' === $max ) {
			return '';
		}

		if ( is_array( $value ) ) {
			$count = count( $value );

			if ( '' !== $min && $count < (int) $min ) {
				/* translators: %d: minimum number of selections. */
				return sprintf( __( 'Select at least %d items.', 'wp-custom-meta-box' ), (int) $min );
			}

			if ( '' !== $max && $count > (int) $max ) {
				/* translators: %d: maximum number of selections. */
				return sprintf( __( 'Select no more than %d items.', 'wp-custom-meta-box' ), (int) $max );
			}

			return '';
		}

		if ( ! is_numeric( $value ) ) {
			return '';
		}

		if ( '' !== $min && (float) $value < (float) $min ) {
			/* translators: %s: minimum value. */
			return sprintf( __( 'Enter a value of %s or more.', 'wp-custom-meta-box' ), (string) $min );
		}

		if ( '' !== $max && (float) $value > (float) $max ) {
			/* translators: %s: maximum value. */
			return sprintf( __( 'Enter a value of %s or less.', 'wp-custom-meta-box' ), (string) $max );
		}

		return '';
	}

	/**
	 * Regular expression check.
	 *
	 * A pattern that fails to compile is reported rather than silently
	 * passing every value, which is the failure mode that lets a broken
	 * validation rule look like it is working.
	 *
	 * @param array<string, mixed> $settings Field settings.
	 * @param array<string, mixed> $field    Field definition.
	 * @param mixed                $value    Submitted value.
	 */
	private function check_pattern( array $settings, array $field, mixed $value ): string {
		$pattern = (string) ( $settings['pattern'] ?? '' );

		if ( '' === $pattern || ! is_scalar( $value ) ) {
			return '';
		}

		$result = @preg_match( Conditional::delimit( $pattern ), (string) $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed pattern is reported below, not surfaced as a PHP warning.

		if ( false === $result ) {
			return __( 'This field has an invalid validation pattern. Check the field settings.', 'wp-custom-meta-box' );
		}

		return 1 === $result ? '' : $this->message( $settings, __( 'This value is not in the expected format.', 'wp-custom-meta-box' ) );
	}

	/**
	 * Whether a value counts as not filled in.
	 *
	 * `0` and `'0'` are answers, so PHP's empty() is the wrong test.
	 *
	 * @param mixed $value Submitted value.
	 */
	private function is_empty( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return array() === $value;
		}

		return null === $value || false === $value || '' === $value;
	}

	/**
	 * The custom message for a field, falling back to a default.
	 *
	 * @param array<string, mixed> $settings Field settings.
	 * @param string               $fallback Default message.
	 */
	private function message( array $settings, string $fallback ): string {
		$custom = (string) ( $settings['validation_message'] ?? '' );

		return '' !== $custom ? $custom : $fallback;
	}

	/**
	 * A field's label, falling back to its name.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	private function label( array $field ): string {
		$label = (string) ( $field['label'] ?? '' );

		return '' !== $label ? $label : (string) ( $field['name'] ?? '' );
	}

	/**
	 * Let PHP hook a custom rule onto any field.
	 *
	 * @param string               $error Error message so far, empty when valid.
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Submitted value.
	 */
	private function filter( string $error, array $field, mixed $value ): string {
		$type = (string) ( $field['type'] ?? '' );
		$name = (string) ( $field['name'] ?? '' );

		/**
		 * Filters the validation result for a value.
		 *
		 * Return a non-empty string to reject the value with that message.
		 * Also fires as `wpcmb/validate/type={$type}` and
		 * `wpcmb/validate/name={$name}` for narrower hooks.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $error Error message, empty when valid.
		 * @param mixed                $value Submitted value.
		 * @param array<string, mixed> $field Field definition.
		 */
		$error = (string) apply_filters( 'wpcmb/validate', $error, $value, $field );
		$error = (string) apply_filters( "wpcmb/validate/type={$type}", $error, $value, $field );

		return (string) apply_filters( "wpcmb/validate/name={$name}", $error, $value, $field );
	}
}
