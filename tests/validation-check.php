<?php
/**
 * Standalone self-check for required fields and sub-field validation.
 *
 * A required field that does not stop a save is worse than no required flag
 * at all: the screen promises something it does not do. These are the rules
 * the publish gate rests on, so they get their own check.
 *
 * Run with `php tests/validation-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Fields\Validator;

$validator = new Validator();

/**
 * One field definition.
 *
 * @param array<string, mixed> $field Overrides.
 *
 * @return array<string, mixed>
 */
function wpcmb_field( array $field ): array {
	return array_merge(
		array(
			'key'      => 'field_' . ( $field['name'] ?? 'x' ),
			'name'     => 'x',
			'label'    => 'X',
			'type'     => 'text',
			'required' => false,
			'settings' => array(),
		),
		$field
	);
}

/* -------------------------------------------------------------------------
 * Required, at the top level.
 * ---------------------------------------------------------------------- */

$required = wpcmb_field( array( 'name' => 'headline', 'label' => 'Headline', 'required' => true ) );

assert( '' !== $validator->validate_field( $required, '' ), 'a required field rejects an empty string' );
assert( '' !== $validator->validate_field( $required, null ), 'a required field rejects a missing value' );
assert( '' !== $validator->validate_field( $required, array() ), 'a required field rejects an empty array' );
assert( '' === $validator->validate_field( $required, 'Something' ), 'a required field accepts a value' );

// `0` is an answer. A number field holding zero must not read as unfilled.
assert( '' === $validator->validate_field( $required, '0' ), 'zero as a string satisfies required' );
assert( '' === $validator->validate_field( $required, 0 ), 'zero as an integer satisfies required' );

$optional = wpcmb_field( array( 'name' => 'subtitle' ) );

assert( '' === $validator->validate_field( $optional, '' ), 'an empty optional field is fine' );

/* An absent value is checked, not skipped: that is what the gate looks for. */
$errors = $validator->validate( array( 'headline' => $required ), array() );

assert( isset( $errors['headline'] ), 'a required field missing from the submission is an error' );

/* A required field hidden by conditional logic cannot block anything. */
$toggle = wpcmb_field( array( 'key' => 'field_toggle', 'name' => 'toggle', 'type' => 'true_false' ) );
$hidden = wpcmb_field(
	array(
		'name'        => 'extra',
		'label'       => 'Extra',
		'required'    => true,
		'conditional' => array(
			'action' => 'show',
			'logic'  => 'all',
			'rules'  => array( array( 'field' => 'field_toggle', 'operator' => '==', 'value' => '1' ) ),
		),
	)
);

$errors = $validator->validate(
	array( 'toggle' => $toggle, 'extra' => $hidden ),
	array( 'toggle' => '', 'extra' => '' )
);

assert( ! isset( $errors['extra'] ), 'a hidden required field must not block a save' );

$errors = $validator->validate(
	array( 'toggle' => $toggle, 'extra' => $hidden ),
	array( 'toggle' => '1', 'extra' => '' )
);

assert( isset( $errors['extra'] ), 'the same field blocks once its condition is met' );

/* -------------------------------------------------------------------------
 * Required, inside a repeater, a group and a flexible layout.
 *
 * Before these existed, marking a repeater column required had no effect at
 * all: the rows were sanitized but never validated.
 * ---------------------------------------------------------------------- */

$repeater = wpcmb_field(
	array(
		'name'       => 'team',
		'label'      => 'Team',
		'type'       => 'repeater',
		'sub_fields' => array(
			wpcmb_field( array( 'key' => 'field_member', 'name' => 'member', 'label' => 'Member', 'required' => true ) ),
			wpcmb_field( array( 'key' => 'field_role', 'name' => 'role', 'label' => 'Role' ) ),
		),
	)
);

assert(
	'' === $validator->validate_field( $repeater, array( array( 'member' => 'Ada', 'role' => '' ) ) ),
	'a row with its required sub field filled in passes'
);

$error = $validator->validate_field(
	$repeater,
	array(
		array( 'member' => 'Ada', 'role' => 'Lead' ),
		array( 'member' => '', 'role' => 'Second' ),
	)
);

assert( '' !== $error, 'an empty required sub field is an error' );
assert( str_contains( $error, 'Row 2' ), 'the message names the row, got: ' . $error );
assert( str_contains( $error, 'Member' ), 'the message names the sub field, got: ' . $error );

/* A group is one row, so it reports without a row number. */
$group = wpcmb_field(
	array(
		'name'       => 'address',
		'type'       => 'group',
		'sub_fields' => array(
			wpcmb_field( array( 'key' => 'field_city', 'name' => 'city', 'label' => 'City', 'required' => true ) ),
		),
	)
);

$error = $validator->validate_field( $group, array( 'city' => '' ) );

assert( '' !== $error, 'a required sub field in a group is checked' );
assert( ! str_contains( $error, 'Row' ), 'a group has no row number, got: ' . $error );
assert( '' === $validator->validate_field( $group, array( 'city' => 'Leeds' ) ), 'a filled group passes' );

/* Flexible content checks the layout the row actually uses. */
$flexible = wpcmb_field(
	array(
		'name'    => 'blocks',
		'type'    => 'flexible_content',
		'layouts' => array(
			array(
				'name'       => 'quote',
				'label'      => 'Quote',
				'sub_fields' => array(
					wpcmb_field( array( 'key' => 'field_quote', 'name' => 'quote', 'label' => 'Quote', 'required' => true ) ),
				),
			),
			array(
				'name'       => 'spacer',
				'label'      => 'Spacer',
				'sub_fields' => array(),
			),
		),
	)
);

assert(
	'' !== $validator->validate_field( $flexible, array( array( '_layout' => 'quote', 'quote' => '' ) ) ),
	'a required field in the chosen layout is checked'
);

assert(
	'' === $validator->validate_field( $flexible, array( array( '_layout' => 'spacer' ) ) ),
	'a layout with no required fields passes'
);

// An orphaned row names a layout that no longer exists. It cannot be fixed
// from the edit screen, so it must not block the save either.
assert(
	'' === $validator->validate_field( $flexible, array( array( '_layout' => 'deleted', 'quote' => '' ) ) ),
	'a row whose layout was deleted does not block a save'
);

/* Sub-field rules other than required are checked too. */
$typed = wpcmb_field(
	array(
		'name'       => 'contacts',
		'type'       => 'repeater',
		'sub_fields' => array(
			wpcmb_field( array( 'key' => 'field_mail', 'name' => 'mail', 'label' => 'Mail', 'type' => 'email' ) ),
		),
	)
);

assert(
	'' !== $validator->validate_field( $typed, array( array( 'mail' => 'not-an-email' ) ) ),
	'a sub field is held to its own type'
);

assert(
	'' === $validator->validate_field( $typed, array( array( 'mail' => '' ) ) ),
	'an empty optional sub field is still fine'
);

/* Nesting recurses: a repeater inside a repeater. */
$nested = wpcmb_field(
	array(
		'name'       => 'outer',
		'type'       => 'repeater',
		'sub_fields' => array(
			wpcmb_field(
				array(
					'key'        => 'field_inner',
					'name'       => 'inner',
					'type'       => 'repeater',
					'sub_fields' => array(
						wpcmb_field( array( 'key' => 'field_deep', 'name' => 'deep', 'label' => 'Deep', 'required' => true ) ),
					),
				)
			),
		),
	)
);

assert(
	'' !== $validator->validate_field( $nested, array( array( 'inner' => array( array( 'deep' => '' ) ) ) ) ),
	'a required field two levels down is still checked'
);

echo "validation-check: OK\n";
