<?php
/**
 * Standalone self-check for REST schema generation.
 *
 * The schema is the contract: WordPress validates, sanitizes, casts and
 * documents every value against it, so a wrong schema silently changes what
 * the API accepts. Run with `php tests/rest-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\FieldTypes\Flexible;
use WPCMB\REST\Schema;

/**
 * Shorthand for a field definition.
 *
 * @param string               $type     Field type.
 * @param array<string, mixed> $overrides Extra properties.
 *
 * @return array<string, mixed>
 */
function wpcmb_rest_field( string $type, array $overrides = array() ): array {
	return array_merge( array( 'name' => 'f', 'type' => $type, 'settings' => array() ), $overrides );
}

/* -------------------------------------------------------------------------
 * Scalar types map to the right JSON type.
 * ---------------------------------------------------------------------- */

$expected = array(
	'text'     => 'string',
	'textarea' => 'string',
	'password' => 'string',
	'slug'     => 'string',
	'number'   => 'number',
	'range'    => 'number',
	'currency' => 'number',
	'rating'   => 'number',
	'toggle'   => 'boolean',
	'image'    => 'integer',
	'file'     => 'integer',
	'user'     => 'integer',
	'taxonomy' => 'integer',
	'gallery'  => 'array',
	'checkbox' => 'array',
	'link'     => 'object',
	'address'  => 'object',
);

foreach ( $expected as $type => $json_type ) {
	$schema = Schema::for_field( wpcmb_rest_field( $type ) );
	assert( $json_type === $schema['type'], "{$type} must be a JSON {$json_type}, got {$schema['type']}" );
}

// Formats are what let core reject a malformed value before any plugin code
// runs, so they must actually be emitted.
assert( 'email' === Schema::for_field( wpcmb_rest_field( 'email' ) )['format'], 'an email field declares its format' );
assert( 'uri' === Schema::for_field( wpcmb_rest_field( 'url' ) )['format'], 'a url field declares its format' );
assert( 'date-time' === Schema::for_field( wpcmb_rest_field( 'datetime' ) )['format'], 'a datetime field declares its format' );
assert( ! isset( Schema::for_field( wpcmb_rest_field( 'text' ) )['format'] ), 'a plain text field declares no format' );

// A label becomes the description, which is what appears in the API's own
// documentation.
assert( 'Byline' === Schema::for_field( wpcmb_rest_field( 'text', array( 'label' => 'Byline' ) ) )['description'], 'the label documents the field' );

/* -------------------------------------------------------------------------
 * Choice fields declare their allowed values.
 * ---------------------------------------------------------------------- */

$select = Schema::for_field( wpcmb_rest_field( 'select', array( 'settings' => array( 'choices' => "red : Red\nblue : Blue" ) ) ) );

assert( isset( $select['enum'] ), 'a select declares an enum' );
assert( in_array( 'red', $select['enum'], true ), 'the enum holds the values, not the labels' );
assert( ! in_array( 'Red', $select['enum'], true ), 'the enum holds the values, not the labels' );

// An optional field has to be clearable, and an enum without the empty
// string would make that a validation error.
assert( in_array( '', $select['enum'], true ), 'the enum allows an empty value so the field can be cleared' );

assert( ! isset( Schema::for_field( wpcmb_rest_field( 'select' ) )['enum'] ), 'a select with no choices declares no enum' );

/* -------------------------------------------------------------------------
 * Structural fields are not exposed at all.
 * ---------------------------------------------------------------------- */

foreach ( array( 'message', 'tab', 'accordion' ) as $type ) {
	assert( ! Schema::is_exposable( wpcmb_rest_field( $type ) ), "{$type} holds no value and must not be exposed" );
}

foreach ( array( 'text', 'repeater', 'group', 'flexible_content' ) as $type ) {
	assert( Schema::is_exposable( wpcmb_rest_field( $type ) ), "{$type} must be exposed" );
}

/* -------------------------------------------------------------------------
 * Composite types describe their contents.
 * ---------------------------------------------------------------------- */

$repeater = Schema::for_field(
	wpcmb_rest_field(
		'repeater',
		array(
			'sub_fields' => array(
				wpcmb_rest_field( 'text', array( 'name' => 'title' ) ),
				wpcmb_rest_field( 'number', array( 'name' => 'count' ) ),
				wpcmb_rest_field( 'tab', array( 'name' => 'divider' ) ),
			),
		)
	)
);

assert( 'array' === $repeater['type'], 'a repeater is an array' );
assert( 'object' === $repeater['items']['type'], 'a repeater holds objects' );
assert( 'string' === $repeater['items']['properties']['title']['type'], 'a sub field is described by its own type' );
assert( 'number' === $repeater['items']['properties']['count']['type'], 'a sub field is described by its own type' );
assert( ! isset( $repeater['items']['properties']['divider'] ), 'a structural sub field is not exposed' );

// Nesting works because the schema is derived, not written by hand.
$nested = Schema::for_field(
	wpcmb_rest_field(
		'repeater',
		array(
			'sub_fields' => array(
				wpcmb_rest_field(
					'repeater',
					array(
						'name'       => 'inner',
						'sub_fields' => array( wpcmb_rest_field( 'text', array( 'name' => 'deep' ) ) ),
					)
				),
			),
		)
	)
);

assert( 'string' === $nested['items']['properties']['inner']['items']['properties']['deep']['type'], 'nested repeaters are described all the way down' );

// Undeclared keys are dropped rather than rejected, so adding a sub field
// does not break clients still sending the old shape.
assert( false === $repeater['items']['additionalProperties'], 'undeclared row keys are dropped' );

$group = Schema::for_field(
	wpcmb_rest_field( 'group', array( 'sub_fields' => array( wpcmb_rest_field( 'email', array( 'name' => 'contact' ) ) ) ) )
);

assert( 'object' === $group['type'], 'a group is an object' );
assert( 'email' === $group['properties']['contact']['format'], 'a group describes its sub fields' );

/* -------------------------------------------------------------------------
 * Flexible content: only what every row shares.
 * ---------------------------------------------------------------------- */

$flexible = Schema::for_field(
	wpcmb_rest_field(
		'flexible_content',
		array(
			'layouts' => array(
				array( 'name' => 'hero', 'sub_fields' => array( wpcmb_rest_field( 'text', array( 'name' => 'heading' ) ) ) ),
				array( 'name' => 'text', 'sub_fields' => array( wpcmb_rest_field( 'textarea', array( 'name' => 'body' ) ) ) ),
			),
		)
	)
);

assert( 'array' === $flexible['type'], 'flexible content is an array' );
assert( array( 'hero', 'text' ) === $flexible['items']['properties'][ Flexible::LAYOUT_KEY ]['enum'], 'every layout name is allowed' );

// A union of all layouts' properties would let a row of one layout claim
// another's fields and still validate, so the item schema stays open instead.
assert( ! isset( $flexible['items']['properties']['heading'] ), 'layout fields are not merged into one shape' );
assert( true === $flexible['items']['additionalProperties'], 'a row may carry its own layout\'s fields' );

/* -------------------------------------------------------------------------
 * Link and address describe their parts.
 * ---------------------------------------------------------------------- */

$link = Schema::for_field( wpcmb_rest_field( 'link' ) );

assert( 'uri' === $link['properties']['url']['format'], 'a link url is a URI' );
assert( array( '', '_blank' ) === $link['properties']['target']['enum'], 'a link target is constrained' );

$address = Schema::for_field( wpcmb_rest_field( 'address' ) );

assert( isset( $address['properties']['postcode'] ), 'an address declares its parts' );
assert( false === $address['additionalProperties'], 'an address drops undeclared parts' );

echo "rest-check: OK\n";
