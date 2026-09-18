<?php
/**
 * Standalone self-check for FieldGroup::sanitize().
 *
 * This is the trust boundary between editor input and stored data, so it gets
 * its own check: `php tests/sanitize-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Fields\FieldGroup;

// A hostile payload: script tags, unknown keys, bad operators, bad params.
$clean = FieldGroup::sanitize(
	array(
		'key'      => 'group_abc123',
		'title'    => "<script>alert(1)</script>Contact\nDetails",
		'evil'     => 'dropped',
		'fields'   => array(
			array(
				'key'          => '../../etc/passwd',
				'name'         => 'First Name!!',
				'label'        => '<b>Name</b>',
				'type'         => 'TEXT',
				'instructions' => '<em>Hi</em><script>x</script>',
				'required'     => '1',
				'wrapper'      => array( 'width' => '250', 'class' => 'a b"c' ),
				'conditional'  => array(
					'action' => 'destroy',
					'logic'  => 'all',
					'rules'  => array( array( 'field' => 'field_xyz789', 'operator' => 'DROP TABLE', 'value' => 'x' ) ),
				),
				'sub_fields'   => array( array( 'name' => 'child', 'type' => 'number' ) ),
			),
			array( 'name' => '', 'label' => 'Nameless field' ),
			'not an array',
		),
		'location' => array(
			array(
				array( 'param' => 'post_type', 'operator' => '!=', 'value' => 'page' ),
				array( 'param' => 'made_up_param', 'operator' => '==', 'value' => 'x' ),
			),
			array(),
		),
		'settings' => array(
			'position'       => 'nowhere',
			'menu_order'     => '7abc',
			'hide_on_screen' => array( 'excerpt', '<script>' ),
			'active'         => 'yes',
		),
	)
);

// Only known top-level keys survive.
assert( array( 'key', 'title', 'fields', 'location', 'settings' ) === array_keys( $clean ), 'unknown top-level keys must be dropped' );

// Markup and newlines are stripped from the title. A script element loses
// its contents too, not just its tags.
assert( 'Contact Details' === $clean['title'], 'title must be plain single-line text, got: ' . $clean['title'] );

// One field survives: the nameless field and the non-array are dropped.
assert( 1 === count( $clean['fields'] ), 'fields without a name must be dropped' );

$field = $clean['fields'][0];

// A malformed key is replaced rather than stored.
assert( 1 === preg_match( '/^field_[a-z0-9]+$/', $field['key'] ), 'malformed field keys must be regenerated' );
assert( 'first_name' === $field['name'], 'field names must be meta-key safe, got: ' . $field['name'] );
assert( 'Name' === $field['label'], 'labels must be plain text' );
assert( 'text' === $field['type'], 'types must be lowercased' );
assert( true === $field['required'], 'required must be boolean' );
assert( str_contains( $field['instructions'], '<em>' ), 'instructions keep safe markup' );
assert( ! str_contains( $field['instructions'], '<script>' ), 'instructions drop scripts' );

// Out-of-range widths and unsafe class names are rejected.
assert( '' === $field['wrapper']['width'], 'widths above 100 must be dropped' );
assert( 'abc' === $field['wrapper']['class'], 'wrapper classes must be class-name safe, got: ' . $field['wrapper']['class'] );

// Conditional logic falls back to known values.
assert( 'show' === $field['conditional']['action'], 'unknown conditional actions fall back to show' );
assert( '==' === $field['conditional']['rules'][0]['operator'], 'unknown operators fall back to ==' );
assert( 'field_xyz789' === $field['conditional']['rules'][0]['field'], 'valid field references are preserved' );

// Sub fields recurse through the same sanitizer.
assert( 1 === count( $field['sub_fields'] ), 'sub fields are sanitized' );
assert( 'child' === $field['sub_fields'][0]['name'], 'sub field names survive' );

// Unknown location params are dropped, and empty rule groups with them.
assert( 1 === count( $clean['location'] ), 'empty rule groups must be dropped' );
assert( 1 === count( $clean['location'][0] ), 'unknown location params must be dropped' );
assert( '!=' === $clean['location'][0][0]['operator'], 'valid location operators survive' );

// Settings fall back to known values.
assert( 'normal' === $clean['settings']['position'], 'unknown positions fall back to normal' );
assert( 7 === $clean['settings']['menu_order'], 'menu order must be an int' );
assert( array( 'excerpt', 'script' ) === $clean['settings']['hide_on_screen'], 'hide_on_screen entries must be keys' );

// A group with no key at all still gets one.
$generated = FieldGroup::sanitize( array() );
assert( 1 === preg_match( '/^group_[a-z0-9]+$/', $generated['key'] ), 'missing group keys must be generated' );
assert( array() === $generated['fields'] && array() === $generated['location'], 'an empty group sanitizes to empty' );

// A field name can never claim a protected meta key. Names go straight to
// update_metadata(), and an underscore is how WordPress and every other plugin
// mark meta as internal — so `_thumbnail_id` would hand everyone who can edit
// the object a writable path to a key something else owns. Creating the field
// needs manage_options; filling it in needs only edit_post, and that is the
// boundary this keeps.
$protected = FieldGroup::sanitize(
	array(
		'fields' => array(
			array( 'name' => '_thumbnail_id', 'type' => 'text' ),
			array( 'name' => '__edit_last', 'type' => 'text' ),
			array( 'name' => '  _wp_page_template  ', 'type' => 'text' ),
			array( 'name' => '___', 'type' => 'text' ),
			array(
				'name'       => 'rows',
				'type'       => 'repeater',
				'sub_fields' => array( array( 'name' => '_edit_lock', 'type' => 'text' ) ),
			),
		),
	)
);

assert(
	array( 'thumbnail_id', 'edit_last', 'wp_page_template', 'rows' ) === array_column( $protected['fields'], 'name' ),
	'leading underscores must be stripped from field names, got: ' . implode( ', ', array_column( $protected['fields'], 'name' ) )
);

// A name of nothing but underscores has nothing left, so the field is dropped
// rather than stored under an empty key.
assert( 4 === count( $protected['fields'] ), 'a field whose name is only underscores must be dropped' );

// Sub fields go through the same rule: a repeater row writes its own meta.
assert(
	'edit_lock' === $protected['fields'][3]['sub_fields'][0]['name'],
	'sub field names must be stripped too, got: ' . $protected['fields'][3]['sub_fields'][0]['name']
);

// The builder names fields in the browser and the server re-sanitizes on save,
// so the two rules have to agree. If they drift, a field is silently renamed
// on save and its stored values are orphaned.
$builder = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/builder.js' );

assert(
	str_contains( $builder, ".replace( /^_+/, '' )" ),
	'builder.js toName() must strip leading underscores, matching sanitize_field_name()'
);
// Sanitized output round-trips through the model unchanged.
$group = FieldGroup::from_array( $clean );
assert( $group->key === $clean['key'], 'the model preserves the key' );
assert( count( $group->fields ) === count( $clean['fields'] ), 'the model preserves fields' );

echo "sanitize-check: OK\n";
