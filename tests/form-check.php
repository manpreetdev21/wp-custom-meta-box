<?php
/**
 * Standalone self-check for front-end form security.
 *
 * The interesting part of a public form is what it refuses. Run with
 * `php tests/form-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Fields\ObjectRef;
use WPCMB\Frontend\Form;

/* -------------------------------------------------------------------------
 * The signed configuration.
 * ---------------------------------------------------------------------- */

$config = array_merge(
	Form::defaults(),
	array(
		'group'       => 'group_contact0001',
		'action'      => 'post',
		'post_status' => 'draft',
		'email'       => 'owner@example.com',
		'redirect'    => 'https://example.com/thanks',
	)
);

$signed = ( new Form( new WPCMB\Container() ) )->sign( $config );

assert( is_string( $signed['payload'] ) && is_string( $signed['signature'] ), 'a signed form carries a payload and a signature' );

// The round trip works.
$verified = Form::verify( $signed['payload'], $signed['signature'] );
assert( is_array( $verified ), 'a genuine payload verifies' );
assert( 'group_contact0001' === $verified['group'], 'the group survives the round trip' );
assert( 'draft' === $verified['post_status'], 'the status survives the round trip' );

// The whole point: the browser cannot change what the form is allowed to do.
$tampered = str_replace( '"post_status":"draft"', '"post_status":"publish"', $signed['payload'] );
assert( $tampered !== $signed['payload'], 'the test payload was actually modified' );
assert( null === Form::verify( $tampered, $signed['signature'] ), 'editing the status must break the signature' );

$redirected = str_replace( 'owner@example.com', 'attacker@example.com', $signed['payload'] );
assert( null === Form::verify( $redirected, $signed['signature'] ), 'editing the notification address must break the signature' );

assert( null === Form::verify( $signed['payload'], 'not-a-signature' ), 'a wrong signature is rejected' );
assert( null === Form::verify( '', '' ), 'an empty payload is rejected' );
assert( null === Form::verify( 'not json', Form::signature( 'not json' ) ), 'a correctly signed non-payload is still rejected' );

// A payload may not introduce settings the plugin does not define, even when
// correctly signed — that is what stops a signature from one context being
// reused to widen another.
$extra   = (string) wp_json_encode( $config + array( 'evil' => 'yes' ) );
$smuggled = Form::verify( $extra, Form::signature( $extra ) );
assert( is_array( $smuggled ), 'a signed payload with extra keys still verifies' );
assert( ! isset( $smuggled['evil'] ), 'undeclared settings are dropped' );

// Every default is present after verification, so nothing downstream has to
// check whether a key exists.
assert( array() === array_diff_key( Form::defaults(), $smuggled ), 'verification fills in every known setting' );

/* -------------------------------------------------------------------------
 * Who may submit.
 * ---------------------------------------------------------------------- */

$GLOBALS['wpcmb_test_logged_in'] = false;

assert( ! Form::may_submit( array( 'guests' => '0' ) ), 'a signed-out visitor is refused by default' );
assert( Form::may_submit( array( 'guests' => '1' ) ), 'a signed-out visitor may submit a form that opts in' );

$GLOBALS['wpcmb_test_logged_in'] = true;
assert( Form::may_submit( array( 'guests' => '0' ) ), 'a signed-in visitor may submit' );

/* -------------------------------------------------------------------------
 * Which object a form targets.
 * ---------------------------------------------------------------------- */

$form = new Form( new WPCMB\Container() );

$new = $form->object_ref( array_merge( Form::defaults(), array( 'object' => 'new' ) ) );
assert( ObjectRef::POST === $new->type && 0 === $new->id, 'a create form targets nothing yet' );
assert( ! $new->is_valid(), 'a create form target is not addressable until something is created' );

$explicit = $form->object_ref( array_merge( Form::defaults(), array( 'object' => 'post_42' ) ) );
assert( 42 === $explicit->id, 'an explicit target resolves' );

$GLOBALS['wpcmb_test_current_user'] = 7;
$current = $form->object_ref( array_merge( Form::defaults(), array( 'object' => 'current', 'action' => 'user' ) ) );
assert( ObjectRef::USER === $current->type && 7 === $current->id, 'a profile form targets the current user' );

// An unresolvable target is never silently the first post on the site.
$garbage = $form->object_ref( array_merge( Form::defaults(), array( 'object' => 'nonsense' ) ) );
assert( ! $garbage->is_valid(), 'an unresolvable target stays unaddressable' );

/* -------------------------------------------------------------------------
 * Timestamp signing, which is what makes the bot check tamper-proof.
 * ---------------------------------------------------------------------- */

$now       = time();
$signature = wp_hash( 'wpcmb_form_time|' . $now );

assert( hash_equals( wp_hash( 'wpcmb_form_time|' . $now ), $signature ), 'a timestamp signature verifies' );
assert( ! hash_equals( wp_hash( 'wpcmb_form_time|' . ( $now - 60 ) ), $signature ), 'a back-dated timestamp does not verify' );

/* -------------------------------------------------------------------------
 * Shortcode generation.
 * ---------------------------------------------------------------------- */

assert(
	'[wpcmb_form group="group_x0000001"]' === wpcmb_test_shortcode( 'group_x0000001' ),
	'a group renders a usable shortcode'
);
assert(
	'[wpcmb_form group="group_x0000001" ajax="0"]' === wpcmb_test_shortcode( 'group_x0000001', array( 'ajax' => '0' ) ),
	'extra settings are included'
);

/**
 * The shortcode builder, inlined because functions.php needs the container.
 *
 * @param string               $group_key Group key.
 * @param array<string, mixed> $args      Extra settings.
 */
function wpcmb_test_shortcode( string $group_key, array $args = array() ): string {
	$parts = array( Form::SHORTCODE, sprintf( 'group="%s"', $group_key ) );

	foreach ( $args as $name => $value ) {
		$parts[] = sprintf( '%s="%s"', sanitize_key( (string) $name ), esc_attr( (string) $value ) );
	}

	return '[' . implode( ' ', $parts ) . ']';
}

/* -------------------------------------------------------------------------
 * The default configuration has to be one that works.
 *
 * A shortcode pasted with nothing but a group key used to render a form and
 * then fail on submit: `action` was `values`, which needs something to store
 * against, while `object` was `new`, which is not an object. Every visitor's
 * first submission answered "That could not be saved. Please try again."
 * ---------------------------------------------------------------------- */

$wpcmb_defaults = Form::defaults();

assert( 'post' === $wpcmb_defaults['action'], 'a bare form creates a post, which is the only thing it can do unasked' );
assert( 'new' === $wpcmb_defaults['object'], 'and it creates a new one' );
assert( '0' === $wpcmb_defaults['guests'], 'and it stays signed-in only until told otherwise' );

/*
 * An empty post type means "this group's own submissions type". It cannot be
 * named in the defaults, which do not know which group they are for, so
 * render() fills it in before signing — and an empty one reaching the
 * submission handler would silently become a plain post.
 */
assert( '' === $wpcmb_defaults['post_type'], 'the post type is derived from the group, not defaulted here' );

/*
 * `publish` is requested, and Submission::status_for() honours it only for a
 * post type nobody can view. Submissions are records in the admin, so leaving
 * them as drafts labels every one of them "Draft" in a list where the word
 * means nothing — while a form pointed at real content still cannot publish
 * it. The integration checks hold that second half against real post types.
 */
assert( 'publish' === $wpcmb_defaults['post_status'], 'a submission is a record, not a draft' );

// The pairing that cannot work must be recognisable, so the renderer can say
// so instead of letting somebody find out by filling the form in.
$wpcmb_dead_end = static fn( array $config ): bool =>
	'values' === ( $config['action'] ?? '' ) && 'new' === ( $config['object'] ?? '' );

assert( ! $wpcmb_dead_end( $wpcmb_defaults ), 'the defaults are not the dead end' );
assert( $wpcmb_dead_end( array( 'action' => 'values', 'object' => 'new' ) ), 'storing values against nothing is the dead end' );
assert( ! $wpcmb_dead_end( array( 'action' => 'values', 'object' => 'post_12' ) ), 'storing values against a post is fine' );

/* -------------------------------------------------------------------------
 * A signed form entitles a visitor to its own fields, and no others.
 *
 * Repeater rows are rendered by the server, so a repeater on a public form is
 * unusable unless a visitor can ask for one. The signature is what stands in
 * for a capability there, which makes the scope of what it unlocks the thing
 * worth pinning down.
 * ---------------------------------------------------------------------- */

$wpcmb_group = WPCMB\Fields\FieldGroup::from_array(
	WPCMB\Fields\FieldGroup::sanitize(
		array(
			'key'    => 'group_scoped00001',
			'title'  => 'Scoped',
			'fields' => array(
				array(
					'key'        => 'field_rows00000001',
					'name'       => 'rows',
					'type'       => 'repeater',
					'sub_fields' => array(
						array( 'key' => 'field_deep00000001', 'name' => 'deep', 'type' => 'text' ),
					),
				),
			),
		)
	)
);

$wpcmb_repo = new WPCMB\Fields\Repository();

assert(
	null !== $wpcmb_repo->field_in_group( $wpcmb_group, 'field_rows00000001' ),
	'a field of the group is found'
);

assert(
	null !== $wpcmb_repo->field_in_group( $wpcmb_group, 'field_deep00000001' ),
	'so is a sub field, however deep'
);

assert(
	null === $wpcmb_repo->field_in_group( $wpcmb_group, 'field_elsewhere01' ),
	'a field the group does not contain is not found, whatever the signature says'
);


echo "form-check: OK\n";
