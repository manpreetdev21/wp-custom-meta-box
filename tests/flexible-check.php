<?php
/**
 * Standalone self-check for flexible content.
 *
 * Run with `php tests/flexible-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Fields\FieldGroup;
use WPCMB\FieldTypes\Flexible;
use WPCMB\FieldTypes\Input;
use WPCMB\FieldTypes\Repeater;

$flexible = new Flexible();
$repeater = new Repeater();
$input    = new Input();

wpcmb_test_filter(
	'wpcmb/field/sub_sanitizer',
	static fn() => static function ( $value, array $field ) use ( $flexible, $repeater, $input ) {
		return match ( (string) ( $field['type'] ?? '' ) ) {
			'flexible_content' => $flexible->sanitize( $value, $field ),
			'repeater'         => $repeater->sanitize( $value, $field ),
			default            => $input->sanitize( $value, $field ),
		};
	}
);

wpcmb_test_filter(
	'wpcmb/field/sub_formatter',
	static fn() => static function ( $value, array $field ) use ( $flexible, $input ) {
		return 'flexible_content' === ( $field['type'] ?? '' )
			? $flexible->format( $value, $field )
			: $input->format( $value, $field );
	}
);

/**
 * A flexible content definition with two layouts.
 *
 * @param array<string, mixed> $settings Field settings.
 *
 * @return array<string, mixed>
 */
function wpcmb_flexible( array $settings = array() ): array {
	return array(
		'key'      => 'field_flex000001',
		'name'     => 'sections',
		'type'     => 'flexible_content',
		'settings' => $settings,
		'layouts'  => array(
			array(
				'key'        => 'layout_hero00001',
				'name'       => 'hero',
				'label'      => 'Hero',
				'settings'   => array( 'icon' => 'dashicons-cover-image', 'category' => 'Headers', 'max' => 1 ),
				'sub_fields' => array(
					array( 'key' => 'field_h100000001', 'name' => 'heading', 'type' => 'text', 'settings' => array() ),
					array( 'key' => 'field_h200000001', 'name' => 'link', 'type' => 'url', 'settings' => array() ),
				),
			),
			array(
				'key'        => 'layout_text00001',
				'name'       => 'text',
				'label'      => 'Text block',
				'settings'   => array( 'category' => 'Content' ),
				'sub_fields' => array(
					array( 'key' => 'field_t100000001', 'name' => 'body', 'type' => 'textarea', 'settings' => array() ),
				),
			),
		),
	);
}

$field = wpcmb_flexible();

/* -------------------------------------------------------------------------
 * Layout resolution.
 * ---------------------------------------------------------------------- */

$layouts = $flexible->layouts( $field );

assert( array( 'hero', 'text' ) === array_keys( $layouts ), 'layouts are keyed by name' );
assert( 'dashicons-cover-image' === $layouts['hero']['icon'], 'a layout keeps its icon' );
assert( 'Headers' === $layouts['hero']['category'], 'a layout keeps its category' );
assert( 1 === $layouts['hero']['max'], 'a layout keeps its usage limit' );
assert( 'text' === $layouts['text']['name'], 'a layout with no icon still resolves' );

/* -------------------------------------------------------------------------
 * Rows record their layout.
 * ---------------------------------------------------------------------- */

$clean = $flexible->sanitize(
	array(
		array( Flexible::LAYOUT_KEY => 'text', 'body' => 'Some words' ),
		array( Flexible::LAYOUT_KEY => 'hero', 'heading' => '  Welcome  ', 'link' => 'https://example.com' ),
	),
	$field
);

assert( 2 === count( $clean ), 'both rows survive' );
assert( 'text' === $clean[0][ Flexible::LAYOUT_KEY ], 'the first row keeps its layout' );
assert( 'hero' === $clean[1][ Flexible::LAYOUT_KEY ], 'the second row keeps its layout' );
assert( 'Welcome' === $clean[1]['heading'], 'sub values are cleaned by their own type' );
assert( ! isset( $clean[0]['heading'] ), 'a row only holds its own layout\'s fields' );

// A row naming a layout that no longer exists is dropped rather than
// re-interpreted as another layout, which would scramble its values into
// fields that were never meant to hold them.
$orphaned = $flexible->sanitize(
	array(
		array( Flexible::LAYOUT_KEY => 'gone', 'heading' => 'Orphan' ),
		array( Flexible::LAYOUT_KEY => 'text', 'body' => 'Kept' ),
	),
	$field
);

assert( 1 === count( $orphaned ), 'a row with an unknown layout is dropped' );
assert( 'Kept' === $orphaned[0]['body'], 'the surviving row is the right one' );

// A row with no layout at all is not guessed at.
assert( array() === $flexible->sanitize( array( array( 'body' => 'No layout' ) ), $field ), 'a row with no layout is dropped' );

// Values are not carried across layouts by position.
$crossed = $flexible->sanitize( array( array( Flexible::LAYOUT_KEY => 'text', 'heading' => 'wrong field' ) ), $field );
assert( ! isset( $crossed[0]['heading'] ), 'a value belonging to another layout is discarded' );

/* -------------------------------------------------------------------------
 * Limits.
 * ---------------------------------------------------------------------- */

// A per-layout maximum is enforced on save, not only in the picker.
$too_many_heroes = $flexible->sanitize(
	array(
		array( Flexible::LAYOUT_KEY => 'hero', 'heading' => 'One' ),
		array( Flexible::LAYOUT_KEY => 'hero', 'heading' => 'Two' ),
		array( Flexible::LAYOUT_KEY => 'text', 'body' => 'Body' ),
	),
	$field
);

assert( 2 === count( $too_many_heroes ), 'a layout beyond its own maximum is dropped' );
assert( 'One' === $too_many_heroes[0]['heading'], 'the first use of a capped layout is kept' );
assert( 'text' === $too_many_heroes[1][ Flexible::LAYOUT_KEY ], 'other layouts are unaffected' );

// The field maximum applies across all layouts.
$capped = wpcmb_flexible( array( 'max' => '1' ) );
assert( 1 === count( $flexible->sanitize( array(
	array( Flexible::LAYOUT_KEY => 'text', 'body' => 'a' ),
	array( Flexible::LAYOUT_KEY => 'text', 'body' => 'b' ),
), $capped ) ), 'the field maximum is enforced' );

/* -------------------------------------------------------------------------
 * Order, shared with the repeater.
 * ---------------------------------------------------------------------- */

$ordered = $flexible->sanitize(
	array(
		'10' => array( Flexible::LAYOUT_KEY => 'text', 'body' => 'last' ),
		'2'  => array( Flexible::LAYOUT_KEY => 'text', 'body' => 'first' ),
	),
	$field
);

assert( 'first' === $ordered[0]['body'], 'rows sort numerically by their posted index' );
assert( 'last' === $ordered[1]['body'], 'rows sort numerically by their posted index' );

/* -------------------------------------------------------------------------
 * Formatting keeps the layout, because templates switch on it.
 * ---------------------------------------------------------------------- */

$formatted = $flexible->format(
	array( array( Flexible::LAYOUT_KEY => 'hero', 'heading' => 'Hi', 'link' => 'https://example.com' ) ),
	$field
);

assert( 'hero' === $formatted[0][ Flexible::LAYOUT_KEY ], 'the layout name survives formatting' );
assert( 'Hi' === $formatted[0]['heading'], 'sub values survive formatting' );
assert( array() === $flexible->format( '', $field ), 'an unset field formats to no rows' );

/* -------------------------------------------------------------------------
 * The stored configuration shape.
 * ---------------------------------------------------------------------- */

$sanitized = FieldGroup::sanitize(
	array(
		'title'  => 'Page builder',
		'fields' => array(
			array(
				'name'    => 'sections',
				'type'    => 'flexible_content',
				'layouts' => array(
					array( 'label' => 'Hero Banner', 'settings' => array( 'icon' => 'dashicons-cover-image' ), 'sub_fields' => array( array( 'name' => 'heading', 'type' => 'text' ) ) ),
					array( 'label' => '', 'name' => '', 'sub_fields' => array() ),
				),
			),
		),
	)
);

$stored = $sanitized['fields'][0];

assert( 1 === count( $stored['layouts'] ), 'a layout with no usable name is dropped' );
assert( 'hero_banner' === $stored['layouts'][0]['name'], 'a layout name is derived from its label, got: ' . $stored['layouts'][0]['name'] );
assert( 1 === preg_match( '/^layout_[a-z0-9]+$/', $stored['layouts'][0]['key'] ), 'a layout gets a generated key' );
assert( 'dashicons-cover-image' === $stored['layouts'][0]['settings']['icon'], 'layout settings survive sanitizing' );
assert( 'heading' === $stored['layouts'][0]['sub_fields'][0]['name'], 'layout sub fields are sanitized too' );

echo "flexible-check: OK\n";
