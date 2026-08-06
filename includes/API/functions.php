<?php
/**
 * Public template functions.
 *
 * The only global functions the plugin defines. Everything here is a thin
 * wrapper over a container service: themes get a short, stable, procedural
 * API, and the implementation stays swappable behind it.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

use WPCMB\Fields\Context;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Resolver;
use WPCMB\Fields\Validator;
use WPCMB\Fields\Values;
use WPCMB\Frontend\Form;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpcmb_get_field' ) ) {
	/**
	 * Read a field value.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier. Defaults to the current post.
	 *                           Accepts an id, a WP_Post/WP_Term/WP_User/WP_Comment,
	 *                           or a string such as `term_5` or `options`.
	 * @param bool   $format     Whether to run the value through field-type formatting.
	 *
	 * @return mixed The value, the field's default, or null.
	 */
	function wpcmb_get_field( string $selector, mixed $identifier = null, bool $format = true ): mixed {
		return wpcmb()->container()->get( Values::class )->get( $selector, $identifier, $format );
	}
}

if ( ! function_exists( 'wpcmb_the_field' ) ) {
	/**
	 * Echo a field value, escaped for HTML output.
	 *
	 * Arrays are joined with a comma. Anything that is not a scalar or an
	 * array of scalars prints nothing rather than "Array": use
	 * wpcmb_get_field() for structured values such as repeater rows.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier.
	 */
	function wpcmb_the_field( string $selector, mixed $identifier = null ): void {
		$value = wpcmb_get_field( $selector, $identifier );

		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
		}

		if ( ! is_scalar( $value ) ) {
			return;
		}

		echo esc_html( (string) $value );
	}
}

if ( ! function_exists( 'wpcmb_get_fields' ) ) {
	/**
	 * Read every field value that applies to an object.
	 *
	 * @param mixed $identifier Object identifier.
	 * @param bool  $format     Whether to run values through field-type formatting.
	 *
	 * @return array<string, mixed> Values keyed by field name.
	 */
	function wpcmb_get_fields( mixed $identifier = null, bool $format = true ): array {
		$context  = Context::for( $identifier );
		$resolver = wpcmb()->container()->get( Resolver::class );
		$values   = wpcmb()->container()->get( Values::class );

		$result = array();

		foreach ( array_keys( $resolver->fields( $context ) ) as $name ) {
			$result[ $name ] = $values->get( $name, $context->ref, $format );
		}

		return $result;
	}
}

if ( ! function_exists( 'wpcmb_has_field' ) ) {
	/**
	 * Whether a value is stored for a field, ignoring its default.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier.
	 */
	function wpcmb_has_field( string $selector, mixed $identifier = null ): bool {
		return wpcmb()->container()->get( Values::class )->has( $selector, $identifier );
	}
}

if ( ! function_exists( 'wpcmb_update_field' ) ) {
	/**
	 * Write a field value.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $value      Value to store, unslashed.
	 * @param mixed  $identifier Object identifier.
	 */
	function wpcmb_update_field( string $selector, mixed $value, mixed $identifier = null ): bool {
		return wpcmb()->container()->get( Values::class )->update( $selector, $value, $identifier );
	}
}

if ( ! function_exists( 'wpcmb_delete_field' ) ) {
	/**
	 * Delete a field value.
	 *
	 * @param string $selector   Field name.
	 * @param mixed  $identifier Object identifier.
	 */
	function wpcmb_delete_field( string $selector, mixed $identifier = null ): bool {
		return wpcmb()->container()->get( Values::class )->delete( $selector, $identifier );
	}
}

if ( ! function_exists( 'wpcmb_get_field_object' ) ) {
	/**
	 * The definition of a field, by name.
	 *
	 * @param string $selector Field name.
	 *
	 * @return array<string, mixed>|null
	 */
	function wpcmb_get_field_object( string $selector ): ?array {
		return wpcmb()->container()->get( Repository::class )->field_by_name( $selector );
	}
}

if ( ! function_exists( 'wpcmb_get_field_groups' ) ) {
	/**
	 * Field groups that apply to an object.
	 *
	 * @param mixed                 $identifier Object identifier.
	 * @param array<string, string> $extra      Screen details, e.g. `array( 'user_form' => 'edit' )`.
	 *
	 * @return array<string, FieldGroup>
	 */
	function wpcmb_get_field_groups( mixed $identifier = null, array $extra = array() ): array {
		return wpcmb()->container()->get( Resolver::class )->groups( Context::for( $identifier, $extra ) );
	}
}

if ( ! function_exists( 'wpcmb_register_field_group' ) ) {
	/**
	 * Register a field group from code.
	 *
	 * Call on the `wpcmb/register_field_groups` action. The group is not
	 * written to the database, and a stored group with the same key wins.
	 *
	 * @param array<string, mixed> $config Group configuration.
	 */
	function wpcmb_register_field_group( array $config ): FieldGroup {
		return wpcmb()->container()->get( Repository::class )->register( $config );
	}
}

if ( ! function_exists( 'wpcmb_form' ) ) {
	/**
	 * Render a field group as a front-end form.
	 *
	 * Same arguments as the `[wpcmb_form]` shortcode.
	 *
	 * @param array<string, mixed> $args    Form configuration. See Form::defaults().
	 * @param bool                 $display Whether to print the form.
	 *
	 * @return string The form markup.
	 */
	function wpcmb_form( array $args = array(), bool $display = true ): string {
		$html = wpcmb()->container()->get( Form::class )->render( $args );

		if ( $display ) {
			// Built entirely from escaped output by the renderer and template.
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $html;
	}
}

if ( ! function_exists( 'wpcmb_form_shortcode' ) ) {
	/**
	 * The shortcode that renders a field group as a form.
	 *
	 * @param string               $group_key Field group key.
	 * @param array<string, mixed> $args      Extra configuration.
	 */
	function wpcmb_form_shortcode( string $group_key, array $args = array() ): string {
		$parts = array( Form::SHORTCODE, sprintf( 'group="%s"', $group_key ) );

		foreach ( $args as $name => $value ) {
			$parts[] = sprintf( '%s="%s"', sanitize_key( (string) $name ), esc_attr( (string) $value ) );
		}

		return '[' . implode( ' ', $parts ) . ']';
	}
}

if ( ! function_exists( 'wpcmb_validate_values' ) ) {
	/**
	 * Validate a set of values against the fields that apply to an object.
	 *
	 * @param array<string, mixed> $values     Values keyed by field name.
	 * @param mixed                $identifier Object identifier.
	 *
	 * @return array<string, string> Error messages keyed by field name. Empty when valid.
	 */
	function wpcmb_validate_values( array $values, mixed $identifier = null ): array {
		$fields = wpcmb()->container()->get( Resolver::class )->fields( Context::for( $identifier ) );

		return wpcmb()->container()->get( Validator::class )->validate( $fields, $values );
	}
}
