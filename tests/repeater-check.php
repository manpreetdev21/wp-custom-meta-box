<?php
/**
 * Standalone self-check for the repeater.
 *
 * Run with `php tests/repeater-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\FieldTypes\Input;
use WPCMB\FieldTypes\Repeater;

$repeater = new Repeater();
$input    = new Input();

// The composite hooks the repeater recurses through. In WordPress these are
// answered by the renderer; here they are answered directly, which is the
// point — the repeater does not know what is on the other side.
wpcmb_test_filter(
	'wpcmb/field/sub_sanitizer',
	static fn() => static function ( $value, array $field ) use ( $repeater, $input ) {
		return 'repeater' === ( $field['type'] ?? '' )
			? $repeater->sanitize( $value, $field )
			: $input->sanitize( $value, $field );
	}
);

wpcmb_test_filter(
	'wpcmb/field/sub_formatter',
	static fn() => static function ( $value, array $field ) use ( $repeater, $input ) {
		return 'repeater' === ( $field['type'] ?? '' )
			? $repeater->format( $value, $field )
			: $input->format( $value, $field );
	}
);

/**
 * A repeater definition.
 *
 * @param array<int, array<string, mixed>> $sub_fields Sub fields.
 * @param array<string, mixed>             $settings   Settings.
 *
 * @return array<string, mixed>
 */
function wpcmb_repeater( array $sub_fields, array $settings = array() ): array {
	return array(
		'key'        => 'field_rep0000001',
		'name'       => 'rows',
		'type'       => 'repeater',
		'settings'   => $settings,
		'sub_fields' => $sub_fields,
	);
}

$simple = wpcmb_repeater(
	array(
		array( 'key' => 'field_a000000001', 'name' => 'title', 'type' => 'text', 'settings' => array() ),
		array( 'key' => 'field_b000000001', 'name' => 'count', 'type' => 'number', 'settings' => array() ),
	)
);

/* -------------------------------------------------------------------------
 * Row order and shape.
 * ---------------------------------------------------------------------- */

// The browser posts rows keyed by the index they had on screen, which has
// gaps once rows have been removed. Order must follow those keys numerically,
// not the order PHP happens to iterate them in.
$submitted = array(
	'7' => array( 'title' => 'Third', 'count' => '3' ),
	'2' => array( 'title' => 'First', 'count' => '1' ),
	'5' => array( 'title' => 'Second', 'count' => '2' ),
);

$clean = $repeater->sanitize( $submitted, $simple );

assert( array( 0, 1, 2 ) === array_keys( $clean ), 'rows are reindexed from zero' );
assert( 'First' === $clean[0]['title'], 'rows keep the order the user saw' );
assert( 'Second' === $clean[1]['title'], 'rows keep the order the user saw' );
assert( 'Third' === $clean[2]['title'], 'rows keep the order the user saw' );

// Gaps of 10 and above would sort wrong under a string comparison.
$many = array();
foreach ( array( 12, 2, 100, 9 ) as $index ) {
	$many[ (string) $index ] = array( 'title' => 'n' . $index, 'count' => '0' );
}
assert(
	array( 'n2', 'n9', 'n12', 'n100' ) === array_column( $repeater->sanitize( $many, $simple ), 'title' ),
	'row keys sort numerically, not as strings'
);

// Every row has every declared sub field, so a template can index into a row
// without checking each key first.
$sparse = $repeater->sanitize( array( array( 'title' => 'Only a title' ) ), $simple );
assert( array_key_exists( 'count', $sparse[0] ), 'a missing sub value is present as null, not absent' );
assert( null === $sparse[0]['count'] || '' === $sparse[0]['count'], 'a missing sub value is empty' );

// Undeclared columns in a submission are discarded.
$extra = $repeater->sanitize( array( array( 'title' => 'x', 'injected' => 'y' ) ), $simple );
assert( ! isset( $extra[0]['injected'] ), 'undeclared sub values are dropped' );

// Sub values are cleaned by their own type.
$typed = $repeater->sanitize( array( array( 'title' => '  spaced  ', 'count' => '7' ) ), $simple );
assert( 'spaced' === $typed[0]['title'], 'text sub values are trimmed by the text type' );
assert( 7 === $typed[0]['count'], 'number sub values stay numbers' );

/* -------------------------------------------------------------------------
 * Limits.
 * ---------------------------------------------------------------------- */

$capped = wpcmb_repeater( $simple['sub_fields'], array( 'max' => '2' ) );
$rows   = array_fill( 0, 5, array( 'title' => 'x', 'count' => '1' ) );

assert( 2 === count( $repeater->sanitize( $rows, $capped ) ), 'a maximum is enforced on save, not only in the browser' );

// Not an array, or empty, is no rows rather than a fatal.
assert( array() === $repeater->sanitize( 'nonsense', $simple ), 'a non-array value is no rows' );
assert( array() === $repeater->sanitize( null, $simple ), 'a null value is no rows' );

/* -------------------------------------------------------------------------
 * Nesting.
 * ---------------------------------------------------------------------- */

$nested = wpcmb_repeater(
	array(
		array( 'key' => 'field_c000000001', 'name' => 'label', 'type' => 'text', 'settings' => array() ),
		array_merge(
			wpcmb_repeater(
				array( array( 'key' => 'field_d000000001', 'name' => 'item', 'type' => 'text', 'settings' => array() ) )
			),
			array( 'key' => 'field_inner00001', 'name' => 'inner' )
		),
	)
);

$nested_clean = $repeater->sanitize(
	array(
		array(
			'label' => 'Outer one',
			'inner' => array(
				'1' => array( 'item' => 'B' ),
				'0' => array( 'item' => 'A' ),
			),
		),
	),
	$nested
);

assert( 'Outer one' === $nested_clean[0]['label'], 'the outer row survives' );
assert( 2 === count( $nested_clean[0]['inner'] ), 'nested rows survive' );
assert( 'A' === $nested_clean[0]['inner'][0]['item'], 'nested rows are ordered independently' );
assert( 'B' === $nested_clean[0]['inner'][1]['item'], 'nested rows are ordered independently' );

/* -------------------------------------------------------------------------
 * Formatting.
 * ---------------------------------------------------------------------- */

$formatted = $repeater->format(
	array( array( 'title' => 'One', 'count' => '5' ) ),
	$simple
);

assert( 5 === $formatted[0]['count'], 'sub values are formatted by their own type on read' );
assert( array() === $repeater->format( '', $simple ), 'an unset repeater formats to no rows' );

// A repeater with no sub fields yields empty rows rather than raw input.
$empty_definition = wpcmb_repeater( array() );
assert( array( array() ) === $repeater->sanitize( array( array( 'anything' => 'x' ) ), $empty_definition ), 'a repeater with no sub fields stores nothing per row' );

echo "repeater-check: OK\n";
