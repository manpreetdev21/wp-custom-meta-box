<?php
/**
 * Standalone self-check for object references and value storage.
 *
 * Run with `php tests/values-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Values;

/* -------------------------------------------------------------------------
 * ObjectRef: every accepted identifier form resolves to one type and id.
 * ---------------------------------------------------------------------- */

$cases = array(
	// identifier            => expected type, expected id
	array( 12, ObjectRef::POST, 12 ),
	array( '12', ObjectRef::POST, 12 ),
	array( 'post_12', ObjectRef::POST, 12 ),
	array( 'term_5', ObjectRef::TERM, 5 ),
	array( 'user_2', ObjectRef::USER, 2 ),
	array( 'comment_9', ObjectRef::COMMENT, 9 ),
	array( 'option', ObjectRef::OPTION, 'options' ),
	array( 'options', ObjectRef::OPTION, 'options' ),
	array( 'options_theme_settings', ObjectRef::OPTION, 'theme_settings' ),
	array( 'option_theme_settings', ObjectRef::OPTION, 'theme_settings' ),
	array( new WP_Post( 33 ), ObjectRef::POST, 33 ),
	array( new WP_Term( 44 ), ObjectRef::TERM, 44 ),
	array( new WP_User( 55 ), ObjectRef::USER, 55 ),
	array( new WP_Comment( 66 ), ObjectRef::COMMENT, 66 ),
);

foreach ( $cases as list( $identifier, $type, $id ) ) {
	$ref   = ObjectRef::from( $identifier );
	$label = is_object( $identifier ) ? get_class( $identifier ) : (string) $identifier;

	assert( $type === $ref->type, "{$label} must resolve to type {$type}, got {$ref->type}" );
	assert( $id === $ref->id, "{$label} must resolve to id " . var_export( $id, true ) . ', got ' . var_export( $ref->id, true ) );
}

// An unrecognised identifier resolves to nothing rather than to post 1.
$unknown = ObjectRef::from( 'widget_sidebar_1' );
assert( ObjectRef::POST === $unknown->type && 0 === $unknown->id, 'unknown identifiers must not address a real object' );
assert( ! $unknown->is_valid(), 'an unresolved reference must be invalid' );

// A filter can claim an identifier the built-in forms reject.
wpcmb_test_filter( 'wpcmb/object_ref', static fn( $ref, string $id ) => new ObjectRef( ObjectRef::OPTION, 'claimed' ) );
assert( 'claimed' === ObjectRef::from( 'widget_sidebar_1' )->id, 'the object_ref filter must be able to claim an identifier' );
$GLOBALS['wpcmb_test_filters']['wpcmb/object_ref'] = array();

// No argument follows the current post.
$GLOBALS['wpcmb_test_current_post'] = 77;
assert( 77 === ObjectRef::from()->id, 'no identifier must resolve to the current post' );
$GLOBALS['wpcmb_test_current_post'] = false;

// Round-trips through its own string form.
assert( ObjectRef::from( (string) new ObjectRef( ObjectRef::TERM, 5 ) )->type === ObjectRef::TERM, 'refs must round-trip through their string form' );

/* -------------------------------------------------------------------------
 * Values: storage, defaults, and the slashing contract.
 * ---------------------------------------------------------------------- */

// One field group, so field_by_name() can resolve a default.
$config = FieldGroup::sanitize(
	array(
		'title'  => 'Details',
		'fields' => array(
			array( 'name' => 'subtitle', 'type' => 'text', 'default' => 'Untitled' ),
			array( 'name' => 'rows', 'type' => 'repeater' ),
		),
	)
);

$GLOBALS['wpcmb_test_posts'] = array( new WP_Post( 100, 'Details', 'publish', 'wpcmb_field_group' ) );
$GLOBALS['wpcmb_test_meta']['post'][100]['_wpcmb_config'] = wp_json_encode( $config );

$repository = new Repository();
$values     = new Values( $repository );

assert( 'subtitle' === ( $repository->field_by_name( 'subtitle' )['name'] ?? '' ), 'fields must be findable by name' );
assert( null === $repository->field_by_name( 'nope' ), 'unknown names must resolve to null' );
assert( array( 'subtitle', 'rows' ) === $repository->field_names(), 'field_names must list every field' );

// Nothing stored: the configured default applies.
assert( ! $values->has( 'subtitle', 200 ), 'nothing is stored yet' );
assert( 'Untitled' === $values->get( 'subtitle', 200 ), 'an unstored field must fall back to its default' );

// Once stored, the default no longer applies, including for an empty string.
$values->update( 'subtitle', '', 200 );
assert( $values->has( 'subtitle', 200 ), 'an empty string is a stored value' );
assert( '' === $values->get( 'subtitle', 200 ), 'a stored empty value must win over the default' );

// Round-trip a string containing backslashes and quotes. update_metadata()
// unslashes whatever it is given, so a value written raw would come back
// with its backslashes eaten.
$tricky = 'C:\\Users\\test "quoted" \\n not a newline';
$values->update( 'subtitle', $tricky, 200 );
assert( $tricky === $values->get( 'subtitle', 200 ), 'backslashes must survive a write/read round trip, got: ' . $values->get( 'subtitle', 200 ) );

// Structured values are stored and returned whole.
$rows = array(
	array( 'label' => 'One', 'url' => 'https://example.com/a?x=1&y=2' ),
	array( 'label' => 'Two', 'url' => '' ),
);
$values->update( 'rows', $rows, 200 );
assert( $rows === $values->get( 'rows', 200 ), 'structured values must round-trip unchanged' );

// Each object type gets its own storage; the same name never collides.
$values->update( 'subtitle', 'post value', 'post_300' );
$values->update( 'subtitle', 'term value', 'term_300' );
$values->update( 'subtitle', 'user value', 'user_300' );
$values->update( 'subtitle', 'comment value', 'comment_300' );
$values->update( 'subtitle', 'option value', 'options_theme' );

assert( 'post value' === $values->get( 'subtitle', 'post_300' ), 'post values are isolated' );
assert( 'term value' === $values->get( 'subtitle', 'term_300' ), 'term values are isolated' );
assert( 'user value' === $values->get( 'subtitle', 'user_300' ), 'user values are isolated' );
assert( 'comment value' === $values->get( 'subtitle', 'comment_300' ), 'comment values are isolated' );
assert( 'option value' === $values->get( 'subtitle', 'options_theme' ), 'option values are isolated' );
assert( 'option value' === get_option( 'wpcmb_theme_subtitle' ), 'options are namespaced by page' );

// Deleting restores the default.
assert( $values->delete( 'subtitle', 200 ), 'delete reports success' );
assert( ! $values->has( 'subtitle', 200 ), 'the value is gone' );
assert( 'Untitled' === $values->get( 'subtitle', 200 ), 'the default applies again after a delete' );

// An unaddressable reference is a no-op, not a write to object 0.
assert( ! $values->update( 'subtitle', 'x' ), 'writing to an unresolved reference must fail' );
assert( ! $values->delete( 'subtitle' ), 'deleting an unresolved reference must fail' );
assert( null === $values->get( 'subtitle', 'garbage' ) || 'Untitled' === $values->get( 'subtitle', 'garbage' ), 'reading an unresolved reference must not read another object' );

// The load and save filters see the value.
wpcmb_test_filter( 'wpcmb/value/save', static fn( $value ) => is_string( $value ) ? strtoupper( $value ) : $value );
wpcmb_test_filter( 'wpcmb/value/load', static fn( $value ) => is_string( $value ) ? $value . '!' : $value );
$values->update( 'subtitle', 'shout', 400 );
assert( 'SHOUT!' === $values->get( 'subtitle', 400 ), 'save and load filters must both apply, got: ' . $values->get( 'subtitle', 400 ) );

echo "values-check: OK\n";
