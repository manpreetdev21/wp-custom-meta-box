<?php
/**
 * Standalone self-check for location matching, conditional logic and validation.
 *
 * Run with `php tests/api-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Fields\Conditional;
use WPCMB\Fields\Context;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Locations;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Resolver;
use WPCMB\Fields\Validator;

/* -------------------------------------------------------------------------
 * Location matching.
 * ---------------------------------------------------------------------- */

/**
 * A context whose parameter values are supplied directly.
 *
 * Context reads them from WordPress; the matcher only cares that a parameter
 * yields a string, a list, or null.
 *
 * @param array<string, mixed> $values Parameter values.
 */
function wpcmb_fake_context( array $values ): Context {
	$GLOBALS['wpcmb_fake_values'] = $values;

	return Context::for( 'post_1' );
}

wpcmb_test_filter(
	'wpcmb/location/value',
	static fn( $value, string $param ) => $GLOBALS['wpcmb_fake_values'][ $param ] ?? null
);

// Context::value() only consults the filter for parameters it does not know,
// so the fake parameters are deliberately named outside the built-in set.
$rule = static fn( string $param, string $operator, string $value ): array =>
	array( 'param' => $param, 'operator' => $operator, 'value' => $value );

$context = wpcmb_fake_context(
	array(
		'fake_type'  => 'page',
		'fake_roles' => array( 'editor', 'author' ),
		'fake_none'  => null,
	)
);

// AND within a rule group.
assert(
	Locations::match( array( array( $rule( 'fake_type', '==', 'page' ) ) ), $context ),
	'a matching rule must match'
);
assert(
	! Locations::match( array( array( $rule( 'fake_type', '==', 'post' ) ) ), $context ),
	'a non-matching rule must not match'
);
assert(
	! Locations::match(
		array( array( $rule( 'fake_type', '==', 'page' ), $rule( 'fake_type', '==', 'post' ) ) ),
		$context
	),
	'every rule in a group must match'
);

// OR across rule groups.
assert(
	Locations::match(
		array(
			array( $rule( 'fake_type', '==', 'post' ) ),
			array( $rule( 'fake_type', '==', 'page' ) ),
		),
		$context
	),
	'any one rule group matching is enough'
);

// List-valued parameters mean "is one of" / "is none of".
assert( Locations::match( array( array( $rule( 'fake_roles', '==', 'author' ) ) ), $context ), 'a list value matches any member' );
assert( ! Locations::match( array( array( $rule( 'fake_roles', '==', 'admin' ) ) ), $context ), 'a list value does not match a non-member' );
assert( Locations::match( array( array( $rule( 'fake_roles', '!=', 'admin' ) ) ), $context ), '!= on a list means none of' );
assert( ! Locations::match( array( array( $rule( 'fake_roles', '!=', 'editor' ) ) ), $context ), '!= on a list rejects a member' );

// A parameter that does not apply never matches, negated or not. Otherwise a
// "post type is not page" rule would start matching users and terms.
assert( ! Locations::match( array( array( $rule( 'fake_none', '==', 'x' ) ) ), $context ), 'an inapplicable parameter never matches' );
assert( ! Locations::match( array( array( $rule( 'fake_none', '!=', 'x' ) ) ), $context ), 'an inapplicable parameter never matches when negated either' );

// No rules at all means the group stays off every screen.
assert( ! Locations::match( array(), $context ), 'a group with no rules must not match everything' );
assert( ! Locations::match( array( array() ), $context ), 'an empty rule group must not match everything' );

/* -------------------------------------------------------------------------
 * Resolver: which groups apply, and are stored groups preferred.
 * ---------------------------------------------------------------------- */

$stored = FieldGroup::sanitize(
	array(
		'key'      => 'group_stored00001',
		'title'    => 'Stored',
		'fields'   => array( array( 'name' => 'stored_field', 'type' => 'text' ) ),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
	)
);

$GLOBALS['wpcmb_test_posts'] = array( new WP_Post( 500, 'Stored', 'publish', 'wpcmb_field_group' ) );
$GLOBALS['wpcmb_test_meta']['post'][500]['_wpcmb_config'] = wp_json_encode( $stored );

$repository = new Repository();
$resolver   = new Resolver( $repository );

// A group registered in code is visible alongside stored ones.
$repository->register(
	array(
		'key'      => 'group_codeonly0001',
		'title'    => 'Code',
		'fields'   => array( array( 'name' => 'code_field', 'type' => 'text' ) ),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
	)
);

// A registered group loses to a stored one with the same key.
$repository->register( array( 'key' => 'group_stored00001', 'title' => 'Shadow', 'fields' => array() ) );

$GLOBALS['wpcmb_fake_values'] = array();
$page_context                 = Context::for( 'post_600' );
$GLOBALS['wpcmb_test_posts'][] = new WP_Post( 600, 'A page', 'publish', 'page' );
$GLOBALS['wpcmb_test_posts'][] = new WP_Post( 601, 'A post', 'publish', 'post' );

$groups = $resolver->groups( $page_context );

assert( 2 === count( $groups ), 'both matching groups apply to a page, got ' . count( $groups ) );
assert( isset( $groups['group_stored00001'] ), 'the stored group applies' );
assert( 'Stored' === $groups['group_stored00001']->title, 'the stored group wins over a registered one with the same key' );
assert( array() === $resolver->groups( Context::for( 'post_601' ) ), 'no group applies to a post' );
assert( array( 'stored_field', 'code_field' ) === array_keys( $resolver->fields( $page_context ) ), 'fields come from every matching group' );

// Registering another group expires the resolver's memo without anyone
// telling it to.
$repository->register(
	array(
		'key'      => 'group_late0000001',
		'title'    => 'Late',
		'fields'   => array( array( 'name' => 'late_field', 'type' => 'text' ) ),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
	)
);
assert( 3 === count( $resolver->groups( $page_context ) ), 'the resolver memo must expire when groups change' );

/* -------------------------------------------------------------------------
 * Conditional logic.
 * ---------------------------------------------------------------------- */

$conditional = static fn( string $logic, array $rules, string $action = 'show' ): array => array(
	'key'         => 'field_target000001',
	'name'        => 'target',
	'type'        => 'text',
	'conditional' => array( 'action' => $action, 'logic' => $logic, 'rules' => $rules ),
);

$values = array(
	'field_a0000000001' => 'yes',
	'field_b0000000001' => '10',
	'field_c0000000001' => array( 'red', 'blue' ),
	'field_d0000000001' => '0',
	'field_e0000000001' => '',
);
$lookup = static fn( string $key ) => $values[ $key ] ?? null;

$c_rule = static fn( string $field, string $operator, string $value ): array =>
	array( 'field' => $field, 'operator' => $operator, 'value' => $value );

assert( ! Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_a0000000001', '==', 'no' ) ) ), $lookup ), 'an unmet rule hides the field' );
assert( Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_a0000000001', '==', 'yes' ) ) ), $lookup ), 'a met rule shows the field' );
assert( Conditional::is_visible( array( 'name' => 'plain' ), $lookup ), 'a field with no logic is always visible' );

// all vs any.
$mixed = array( $c_rule( 'field_a0000000001', '==', 'yes' ), $c_rule( 'field_b0000000001', '==', '99' ) );
assert( ! Conditional::is_visible( $conditional( 'all', $mixed ), $lookup ), 'all requires every rule' );
assert( Conditional::is_visible( $conditional( 'any', $mixed ), $lookup ), 'any requires one rule' );

// hide inverts the outcome.
assert( ! Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_a0000000001', '==', 'yes' ) ), 'hide' ), $lookup ), 'the hide action inverts a met rule' );

// Numeric comparisons are numeric, not lexical: "9" is not greater than "10".
assert( Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_b0000000001', '>', '9' ) ) ), $lookup ), '10 > 9' );
assert( ! Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_b0000000001', '>', '10' ) ) ), $lookup ), '10 is not > 10' );
assert( ! Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_a0000000001', '>', '5' ) ) ), $lookup ), 'a non-numeric value is never greater than a number' );

// Multi-value fields satisfy a comparison if any of their values do.
assert( Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_c0000000001', '==', 'blue' ) ) ), $lookup ), 'a list value matches any member' );
assert( ! Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_c0000000001', '==', 'green' ) ) ), $lookup ), 'a list value does not match a non-member' );

// Zero is an answer, not emptiness.
assert( ! Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_d0000000001', 'empty', '' ) ) ), $lookup ), '"0" must not count as empty' );
assert( Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_e0000000001', 'empty', '' ) ) ), $lookup ), 'an empty string is empty' );
assert( Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_d0000000001', 'not_empty', '' ) ) ), $lookup ), '"0" is not empty' );

// A rule pointing at a deleted field is unmet, not fatal.
assert( ! Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_gone000000001', '==', 'x' ) ) ), $lookup ), 'a rule on a missing field is unmet' );

assert( Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_a0000000001', 'contains', 'ye' ) ) ), $lookup ), 'contains matches a substring' );
assert( Conditional::is_visible( $conditional( 'all', array( $c_rule( 'field_b0000000001', 'pattern', '^\d+$' ) ) ), $lookup ), 'pattern matches a regex' );

/* -------------------------------------------------------------------------
 * Validation.
 * ---------------------------------------------------------------------- */

$validator = new Validator();

$field = static fn( array $overrides ): array => array_merge(
	array( 'key' => 'field_v0000000001', 'name' => 'v', 'label' => 'Value', 'type' => 'text', 'required' => false, 'settings' => array() ),
	$overrides
);

assert( '' !== $validator->validate_field( $field( array( 'required' => true ) ), '' ), 'a required empty field is invalid' );
assert( '' === $validator->validate_field( $field( array( 'required' => true ) ), '0' ), '"0" satisfies required' );
assert( '' === $validator->validate_field( $field( array() ), '' ), 'an optional empty field is valid' );

// An optional empty field must not be rejected by its format rules.
assert( '' === $validator->validate_field( $field( array( 'type' => 'email' ) ), '' ), 'an optional empty email is valid' );
assert( '' !== $validator->validate_field( $field( array( 'type' => 'email' ) ), 'not-an-email' ), 'a malformed email is invalid' );
assert( '' === $validator->validate_field( $field( array( 'type' => 'email' ) ), 'a@b.com' ), 'a valid email passes' );
assert( '' !== $validator->validate_field( $field( array( 'type' => 'url' ) ), 'example.com' ), 'a bare host is not a URL' );
assert( '' === $validator->validate_field( $field( array( 'type' => 'url' ) ), 'https://example.com' ), 'a valid URL passes' );
assert( '' !== $validator->validate_field( $field( array( 'type' => 'number' ) ), 'abc' ), 'a non-number is invalid' );

// Ranges apply to numbers and to how many items are selected.
$ranged = $field( array( 'type' => 'number', 'settings' => array( 'min' => '5', 'max' => '10' ) ) );
assert( '' !== $validator->validate_field( $ranged, '4' ), 'below min is invalid' );
assert( '' === $validator->validate_field( $ranged, '5' ), 'the minimum itself is valid' );
assert( '' !== $validator->validate_field( $ranged, '11' ), 'above max is invalid' );

$multi = $field( array( 'type' => 'checkbox', 'settings' => array( 'min' => '2' ) ) );
assert( '' !== $validator->validate_field( $multi, array( 'a' ) ), 'too few selections is invalid' );
assert( '' === $validator->validate_field( $multi, array( 'a', 'b' ) ), 'enough selections is valid' );

// Lengths.
$bounded = $field( array( 'settings' => array( 'maxlength' => '3' ) ) );
assert( '' !== $validator->validate_field( $bounded, 'abcd' ), 'over maxlength is invalid' );
assert( '' === $validator->validate_field( $bounded, 'abc' ), 'at maxlength is valid' );

// A broken pattern is reported, not silently passed.
$broken = $field( array( 'settings' => array( 'pattern' => '([' ) ) );
assert( '' !== $validator->validate_field( $broken, 'anything' ), 'a malformed pattern must be reported, not ignored' );

$patterned = $field( array( 'settings' => array( 'pattern' => '^[A-Z]{3}$', 'validation_message' => 'Three capitals please.' ) ) );
assert( 'Three capitals please.' === $validator->validate_field( $patterned, 'abc' ), 'a custom message is used' );
assert( '' === $validator->validate_field( $patterned, 'ABC' ), 'a matching pattern passes' );

// A hidden field is not validated: a required field the user never saw must
// not block the save.
$fields = array(
	'toggle' => array( 'key' => 'field_t0000000001', 'name' => 'toggle', 'type' => 'text', 'settings' => array() ),
	'extra'  => array(
		'key'         => 'field_x0000000001',
		'name'        => 'extra',
		'label'       => 'Extra',
		'type'        => 'text',
		'required'    => true,
		'settings'    => array(),
		'conditional' => array(
			'action' => 'show',
			'logic'  => 'all',
			'rules'  => array( array( 'field' => 'field_t0000000001', 'operator' => '==', 'value' => 'on' ) ),
		),
	),
);

assert( array() === $validator->validate( $fields, array( 'toggle' => 'off', 'extra' => '' ) ), 'a hidden required field must not block a save' );
assert( array( 'extra' ) === array_keys( $validator->validate( $fields, array( 'toggle' => 'on', 'extra' => '' ) ) ), 'a visible required field must block a save' );

// A PHP rule can reject anything.
wpcmb_test_filter( 'wpcmb/validate/name=v', static fn( string $error, $value ): string => 'nope' === $value ? 'Rejected.' : $error );
assert( 'Rejected.' === $validator->validate_field( $field( array() ), 'nope' ), 'a custom PHP rule can reject a value' );
assert( '' === $validator->validate_field( $field( array() ), 'fine' ), 'a custom PHP rule leaves other values alone' );

echo "api-check: OK\n";
