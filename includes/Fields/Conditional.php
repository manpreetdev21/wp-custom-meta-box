<?php
/**
 * Conditional logic evaluation.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a field is visible, given the values around it.
 *
 * The browser hides and shows fields live; this is the same decision made on
 * the server. Both are needed and for different reasons: without the server
 * copy, a "required" rule on a hidden field would reject a save the user
 * could not see how to fix, and anyone could post values for fields the form
 * never showed them.
 */
final class Conditional {

	/**
	 * Whether a field is visible.
	 *
	 * A field with no conditional logic is always visible. A rule pointing at
	 * a field key that does not exist is treated as unmet rather than as an
	 * error, so deleting a field cannot make an unrelated one vanish.
	 *
	 * @param array<string, mixed> $field  Field definition.
	 * @param callable             $lookup Given a field key, returns that field's current value.
	 */
	public static function is_visible( array $field, callable $lookup ): bool {
		$conditional = $field['conditional'] ?? array();

		if ( ! is_array( $conditional ) || empty( $conditional['rules'] ) ) {
			return true;
		}

		$rules       = (array) $conditional['rules'];
		$require_all = 'any' !== ( $conditional['logic'] ?? 'all' );
		$met         = array();

		foreach ( $rules as $rule ) {
			$met[] = is_array( $rule ) && self::rule_met( $rule, $lookup( (string) ( $rule['field'] ?? '' ) ) );
		}

		$satisfied = $require_all ? ! in_array( false, $met, true ) : in_array( true, $met, true );
		$visible   = 'hide' === ( $conditional['action'] ?? 'show' ) ? ! $satisfied : $satisfied;

		/**
		 * Filters whether conditional logic makes a field visible.
		 *
		 * @since 1.0.0
		 *
		 * @param bool                 $visible Whether the field is visible.
		 * @param array<string, mixed> $field   Field definition.
		 */
		return (bool) apply_filters( 'wpcmb/conditional/visible', $visible, $field );
	}

	/**
	 * Whether one rule is met by a value.
	 *
	 * Comparisons are string-based except for the numeric operators, because
	 * stored values arrive as strings from form input and as their original
	 * types from code. `>` and `<` on non-numeric values are never met rather
	 * than falling back to PHP's string comparison, which would make
	 * "greater than 10" true for the value "9".
	 *
	 * @param array<string, mixed> $rule  Rule definition.
	 * @param mixed                $value Current value of the referenced field.
	 */
	private static function rule_met( array $rule, mixed $value ): bool {
		$operator = (string) ( $rule['operator'] ?? '==' );
		$expected = (string) ( $rule['value'] ?? '' );

		// A multi-value field satisfies a comparison if any of its values do.
		if ( is_array( $value ) && ! in_array( $operator, array( 'empty', 'not_empty' ), true ) ) {
			foreach ( $value as $item ) {
				if ( self::rule_met( $rule, $item ) ) {
					return true;
				}
			}

			return false;
		}

		$actual = is_scalar( $value ) ? (string) $value : '';

		return match ( $operator ) {
			'!='           => $actual !== $expected,
			'>'            => is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual > (float) $expected,
			'<'            => is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual < (float) $expected,
			'contains'     => '' !== $expected && str_contains( $actual, $expected ),
			'not_contains' => '' === $expected || ! str_contains( $actual, $expected ),
			'empty'        => self::is_empty( $value ),
			'not_empty'    => ! self::is_empty( $value ),
			'pattern'      => '' !== $expected && 1 === preg_match( self::delimit( $expected ), $actual ),
			default        => $actual === $expected,
		};
	}

	/**
	 * Whether a value counts as empty.
	 *
	 * `0` and `'0'` are real answers, so PHP's empty() is the wrong test here.
	 *
	 * @param mixed $value Value to test.
	 */
	private static function is_empty( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return array() === $value;
		}

		return null === $value || false === $value || '' === $value;
	}

	/**
	 * Wrap a user-supplied pattern in delimiters and disable backtracking risk.
	 *
	 * Patterns come from the field group editor, which only an administrator
	 * can reach, but they are still quoted so a stray delimiter cannot change
	 * the pattern's meaning or enable modifiers such as `e`.
	 *
	 * @param string $pattern Raw pattern.
	 */
	public static function delimit( string $pattern ): string {
		return '/' . str_replace( '/', '\/', $pattern ) . '/';
	}
}
