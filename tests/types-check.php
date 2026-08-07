<?php
/**
 * Standalone self-check for field types.
 *
 * Covers the part of a field type that has no WordPress in it: which types
 * exist, and how each one cleans and formats a value. Rendering is checked
 * against the real WordPress install instead, where the escaping functions
 * are the real ones.
 *
 * Run with `php tests/types-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\FieldTypes\Choice;
use WPCMB\FieldTypes\Enhanced;
use WPCMB\FieldTypes\Input;
use WPCMB\FieldTypes\Link;
use WPCMB\FieldTypes\Textarea;

/**
 * Shorthand for a field definition.
 *
 * @param string               $type     Field type.
 * @param array<string, mixed> $settings Type settings.
 * @param array<string, mixed> $extra    Other field properties.
 *
 * @return array<string, mixed>
 */
function wpcmb_field( string $type, array $settings = array(), array $extra = array() ): array {
	return array_merge( array( 'type' => $type, 'name' => 'f', 'settings' => $settings ), $extra );
}

/* -------------------------------------------------------------------------
 * Input: one class, many types.
 * ---------------------------------------------------------------------- */

$input = new Input();

assert( isset( $input->types()['text'], $input->types()['uuid'] ), 'Input covers a family of types' );

// Type-specific cleaning.
assert( 'a@b.com' === $input->sanitize( ' a@b.com ', wpcmb_field( 'email' ) ), 'email is trimmed' );
assert( '' === $input->sanitize( 'not an email', wpcmb_field( 'email' ) ), 'a malformed email is dropped' );
assert( '#ff0000' === $input->sanitize( '#ff0000', wpcmb_field( 'color' ) ), 'a valid hex colour is kept' );
assert( '' === $input->sanitize( 'red', wpcmb_field( 'color' ) ), 'a non-hex colour is dropped' );
assert( 'my-title' === $input->sanitize( 'My Title', wpcmb_field( 'slug' ) ), 'a slug is slugified' );

// sanitize_url() prepends a scheme to anything, so free text would otherwise
// be stored as http://not%20a%20url — a value that looks real and is not.
assert( '' === $input->sanitize( 'not a url', wpcmb_field( 'url' ) ), 'free text is not a URL' );
assert( 'https://example.com/a?b=1' === $input->sanitize( ' https://example.com/a?b=1 ', wpcmb_field( 'url' ) ), 'a real URL survives' );
foreach ( array( '/about', '#section', 'mailto:a@b.com', 'tel:+441234567890' ) as $relative ) {
	assert( '' !== $input->sanitize( $relative, wpcmb_field( 'url' ) ), "a URL field must accept {$relative}" );
}

// Numbers keep their type rather than becoming strings.
assert( 5 === $input->sanitize( '5', wpcmb_field( 'number' ) ), 'a whole number stays an int' );
assert( 5.5 === $input->sanitize( '5.5', wpcmb_field( 'number' ) ), 'a decimal stays a float' );
assert( '' === $input->sanitize( 'abc', wpcmb_field( 'number' ) ), 'a non-number is dropped' );

// Date, time and datetime only accept what the native controls submit, so a
// hand-built request cannot store free text in a date field.
assert( '2026-08-04' === $input->sanitize( '2026-08-04', wpcmb_field( 'date' ) ), 'an ISO date is kept' );
assert( '' === $input->sanitize( '4th August', wpcmb_field( 'date' ) ), 'a non-ISO date is dropped' );
assert( '13:45' === $input->sanitize( '13:45', wpcmb_field( 'time' ) ), 'an ISO time is kept' );
assert( '' === $input->sanitize( '2026-08-04', wpcmb_field( 'time' ) ), 'a date is not a time' );
assert( '2026-08-04T13:45' === $input->sanitize( '2026-08-04T13:45', wpcmb_field( 'datetime' ) ), 'an ISO datetime is kept' );

// A UUID field always holds a UUID.
$generated = $input->sanitize( 'nonsense', wpcmb_field( 'uuid' ) );
assert( wp_is_uuid( $generated ), 'an invalid UUID is replaced with a real one' );
assert( $generated === $input->sanitize( $generated, wpcmb_field( 'uuid' ) ), 'a valid UUID is kept' );

// Every sanitizer must be idempotent: a value is cleaned once on the edit
// screen and again in the value pipeline.
foreach ( array( 'text', 'email', 'url', 'color', 'slug', 'number', 'date', 'phone' ) as $type ) {
	foreach ( array( 'a@b.com', 'https://example.com/x?y=1', '#ff0000', 'Some Text', '5.5', '2026-08-04' ) as $sample ) {
		$field = wpcmb_field( $type );
		$once  = $input->sanitize( $sample, $field );
		assert( $once === $input->sanitize( $once, $field ), "sanitize must be idempotent for {$type} given {$sample}" );
	}
}

/* -------------------------------------------------------------------------
 * Textarea.
 * ---------------------------------------------------------------------- */

$textarea = new Textarea();

$multiline = "line one\nline two";
assert( $multiline === $textarea->sanitize( $multiline, wpcmb_field( 'textarea' ) ), 'line breaks survive' );
assert( '' === $textarea->sanitize( array( 'x' ), wpcmb_field( 'textarea' ) ), 'a non-scalar is dropped' );

/* -------------------------------------------------------------------------
 * Choice: only offered values may be stored.
 * ---------------------------------------------------------------------- */

$choice  = new Choice();
$options = wpcmb_field( 'select', array( 'choices' => "red : Red\nblue : Blue" ) );

assert( 'red' === $choice->sanitize( 'red', $options ), 'an offered value is kept' );
assert( '' === $choice->sanitize( 'green', $options ), 'a value that was never offered is rejected' );
assert( 'Red' === $choice->format( 'red', wpcmb_field( 'select', array( 'choices' => "red : Red", 'return_format' => 'label' ) ) ), 'label format returns the label' );

// A bare list of lines works too, using each line as both value and label.
$plain = wpcmb_field( 'radio', array( 'choices' => "Small\nLarge" ) );
assert( 'Small' === $choice->sanitize( 'Small', $plain ), 'a bare choice list works' );

// Multi-value fields keep a list; single-value ones keep the first match.
$multi = wpcmb_field( 'checkbox', array( 'choices' => "a\nb\nc" ) );
assert( array( 'a', 'c' ) === $choice->sanitize( array( 'a', 'c', 'zzz' ), $multi ), 'a checkbox list keeps only offered values' );
assert( array() === $choice->sanitize( '', $multi ), 'an emptied checkbox list is an empty list' );

// A toggle is a real boolean, and its hidden companion field is respected.
assert( true === $choice->sanitize( '1', wpcmb_field( 'toggle' ) ), 'a checked toggle is true' );
assert( false === $choice->sanitize( '0', wpcmb_field( 'toggle' ) ), 'an unchecked toggle is false' );
assert( false === $choice->sanitize( '', wpcmb_field( 'toggle' ) ), 'an absent toggle is false' );

// Rating builds its own scale.
assert( '3' === $choice->sanitize( '3', wpcmb_field( 'rating', array( 'max' => '5' ) ) ), 'a rating within range is kept' );
assert( '' === $choice->sanitize( '9', wpcmb_field( 'rating', array( 'max' => '5' ) ) ), 'a rating beyond the scale is rejected' );

/* -------------------------------------------------------------------------
 * Link: three parts that live or die together.
 * ---------------------------------------------------------------------- */

$link = new Link();

$clean = $link->sanitize(
	array( 'url' => 'https://example.com', 'title' => '<b>Home</b>', 'target' => '_blank' ),
	wpcmb_field( 'link' )
);

assert( 'https://example.com' === $clean['url'], 'the URL survives' );
assert( 'Home' === $clean['title'], 'the title is plain text' );
assert( '_blank' === $clean['target'], 'a new-tab link keeps its target' );
assert( '' === $link->sanitize( array( 'url' => '', 'title' => 'Orphan' ), wpcmb_field( 'link' ) ), 'a link with no URL stores nothing' );
assert( '' === $link->sanitize( array( 'url' => 'https://e.com', 'target' => 'javascript:x' ), wpcmb_field( 'link' ) )['target'], 'an unexpected target is dropped' );
assert( 'https://e.com' === $link->format( array( 'url' => 'https://e.com' ), wpcmb_field( 'link', array( 'return_format' => 'url' ) ) ), 'url format returns the URL' );

/* -------------------------------------------------------------------------
 * Enhanced: the formats that must not be trusted loosely.
 * ---------------------------------------------------------------------- */

$enhanced = new Enhanced();

// A signature is a canvas export. Anything else — an SVG data URI, a remote
// URL — would end up in a src attribute somewhere, so only PNG data is kept.
$png = 'data:image/png;base64,iVBORw0KGgo=';
assert( $png === $enhanced->sanitize( $png, wpcmb_field( 'signature' ) ), 'a PNG data URI is kept' );
assert( '' === $enhanced->sanitize( 'data:image/svg+xml;base64,PHN2Zz4=', wpcmb_field( 'signature' ) ), 'an SVG data URI is rejected' );
assert( '' === $enhanced->sanitize( 'https://example.com/sig.png', wpcmb_field( 'signature' ) ), 'a remote URL is rejected' );

// A map value is a coordinate pair, not free text.
assert( '51.5,-0.12' === $enhanced->sanitize( '51.5,-0.12', wpcmb_field( 'map' ) ), 'a coordinate pair is kept' );
assert( '' === $enhanced->sanitize( 'somewhere nice', wpcmb_field( 'map' ) ), 'free text is not a map value' );
assert( 51.5 === $enhanced->format( '51.5,-0.12', wpcmb_field( 'map' ) )['lat'], 'a map value formats to lat/lng' );

// An address stores nothing when every part is blank.
assert( '' === $enhanced->sanitize( array( 'line1' => '', 'city' => '' ), wpcmb_field( 'address' ) ), 'an empty address stores nothing' );
$address = $enhanced->sanitize( array( 'line1' => '1 High St', 'city' => 'London', 'unexpected' => 'x' ), wpcmb_field( 'address' ) );
assert( '1 High St' === $address['line1'], 'address parts survive' );
assert( ! isset( $address['unexpected'] ), 'undeclared address parts are dropped' );

/* -------------------------------------------------------------------------
 * Colour: the swatch is decoration, the code is the value.
 *
 * A native colour input has no empty state — browsers substitute #000000 —
 * so if the swatch carried the field name every untouched colour field would
 * silently save black. These assertions pin the input that owns the name.
 * ---------------------------------------------------------------------- */

ob_start();
( new Input() )->render( wpcmb_field( 'color' ), '', 'wpcmb_values[shade]', 'wpcmb-g-shade' );
$colour = ob_get_clean();

assert( 1 === substr_count( $colour, 'name=' ), 'a colour field submits exactly one value' );
assert( str_contains( $colour, 'type="text" name="wpcmb_values[shade]"' ) || preg_match( '/name="wpcmb_values\[shade\]"[^>]*class="wpcmb-input wpcmb-color__code"/', $colour ), 'the hex code input is the one that submits' );
assert( ! preg_match( '/type="color"[^>]*name=/', $colour ), 'the swatch never submits' );
assert( str_contains( $colour, 'tabindex="-1"' ), 'the swatch is not a second tab stop for one value' );

// An unset colour still needs something to show, without claiming a value.
assert( str_contains( $colour, 'value="#000000"' ), 'the swatch falls back to black when unset' );
assert( ! preg_match( '/class="wpcmb-input wpcmb-color__code"[^>]*value=/', $colour ), 'an unset colour submits nothing' );

ob_start();
( new Input() )->render( wpcmb_field( 'color' ), '#AABBCC', 'wpcmb_values[shade]', 'wpcmb-g-shade' );
$set = ob_get_clean();

assert( 2 === substr_count( $set, 'value="#AABBCC"' ), 'a stored colour shows in both the swatch and the code' );

/* -------------------------------------------------------------------------
 * Choice: the controls each type actually renders.
 * ---------------------------------------------------------------------- */

$choice = new Choice();

/**
 * Render one choice field and return its markup.
 *
 * @param string               $type     Field type.
 * @param array<string, mixed> $settings Field settings.
 * @param mixed                $value    Current value.
 */
function wpcmb_choice_markup( string $type, array $settings = array(), mixed $value = '' ): string {
	ob_start();
	( new Choice() )->render(
		wpcmb_field( $type, $settings ),
		$value,
		'wpcmb_values[pick]',
		'wpcmb-g-pick'
	);

	return (string) ob_get_clean();
}

// Country and region lists run to hundreds of entries. Rendered as radios —
// which is what happened before — a country field is a page of radio buttons.
assert( str_contains( wpcmb_choice_markup( 'country' ), '<select' ), 'a country field is a dropdown' );
assert( str_contains( wpcmb_choice_markup( 'state' ), '<select' ), 'a region field is a dropdown' );
assert( ! str_contains( wpcmb_choice_markup( 'country' ), 'type="radio"' ), 'and not a radio list' );

// The bundled list is what makes the field usable out of the box; regions
// used to come back empty, which rendered a control with no options at all.
assert( substr_count( wpcmb_choice_markup( 'country' ), '<option' ) > 200, 'countries are populated' );
assert( substr_count( wpcmb_choice_markup( 'state' ), '<option' ) > 40, 'regions are populated' );
assert( str_contains( wpcmb_choice_markup( 'country' ), '>France<' ), 'countries carry names, not bare codes' );

// Multiple has to reach country and region too, or the setting shows in the
// editor and does nothing.
$multi = wpcmb_choice_markup( 'country', array( 'multiple' => '1' ) );
assert( str_contains( $multi, 'multiple' ), 'a country field can accept several' );
assert( str_contains( $multi, 'name="wpcmb_values[pick][]"' ), 'and posts as a list' );
assert( is_array( $choice->sanitize( array( 'FR', 'DE' ), wpcmb_field( 'country', array( 'multiple' => '1' ) ) ) ), 'several countries survive' );
assert( 'FR' === $choice->sanitize( array( 'FR', 'DE' ), wpcmb_field( 'country' ) ), 'a single country keeps one' );

/* True/false: a boolean, like toggle, with both states named. */

assert( true === $choice->sanitize( '1', wpcmb_field( 'true_false' ) ), 'on stores true' );
assert( false === $choice->sanitize( '0', wpcmb_field( 'true_false' ) ), 'off stores false' );
assert( false === $choice->sanitize( '', wpcmb_field( 'true_false' ) ), 'absent stores false' );
assert( true === $choice->format( 1, wpcmb_field( 'true_false' ) ), 'it formats as a boolean' );

$tf = wpcmb_choice_markup( 'true_false', array( 'on_label' => 'Yes please', 'off_label' => 'No thanks' ) );
assert( str_contains( $tf, 'wpcmb-switch' ), 'true/false renders as a switch' );
assert( str_contains( $tf, 'data-wpcmb-on="Yes please"' ), 'the on label is carried' );
assert( str_contains( $tf, 'data-wpcmb-off="No thanks"' ), 'and the off label too' );

// The hidden partner is what tells the save path "this was on the form and
// left off", as opposed to "this field was never shown".
assert( substr_count( $tf, 'name="wpcmb_values[pick]"' ) === 2, 'an unchecked switch still posts' );

/* Rating: emitted highest-first so CSS can light up every star below. */

$rating = wpcmb_choice_markup( 'rating', array( 'max' => '5' ) );
preg_match_all( '/value="(\d)"/', $rating, $stars );
assert( array( '5', '4', '3', '2', '1' ) === $stars[1], 'stars are emitted in reverse' );

/* The site-managed lists replace the bundled ones. */

update_option( Choice::COUNTRIES_OPTION, "ZZ : Atlantis\nYY : Avalon" );
$managed = wpcmb_choice_markup( 'country' );

// Two entries plus the empty "— Select —" a single-value dropdown always has.
assert( 3 === substr_count( $managed, '<option value="' ), 'a managed list replaces the bundled one' );
assert( str_contains( $managed, '>Atlantis<' ), 'and is what gets offered' );
assert( ! str_contains( $managed, '>France<' ), 'the bundled list is gone, not merged' );
assert( 'ZZ' === $choice->sanitize( 'ZZ', wpcmb_field( 'country' ) ), 'a managed value is accepted' );
assert( '' === $choice->sanitize( 'FR', wpcmb_field( 'country' ) ), 'and one outside the list is not' );
delete_option( Choice::COUNTRIES_OPTION );

/* -------------------------------------------------------------------------
 * Icon: three kinds of value in one string, and nothing else.
 * ---------------------------------------------------------------------- */

$enhanced_icon = wpcmb_field( 'icon' );

assert( 'dashicons-star-filled' === $enhanced->sanitize( 'dashicons-star-filled', $enhanced_icon ), 'a Dashicon name is kept' );
assert( '42' === $enhanced->sanitize( '42', $enhanced_icon ), 'an attachment id is kept' );
assert( 'https://e.com/i.png' === $enhanced->sanitize( 'https://e.com/i.png', $enhanced_icon ), 'a URL is kept' );

// The value lands in a class attribute or a src attribute wherever a theme
// prints it, so anything that is none of the three has to be discarded.
assert( '' === $enhanced->sanitize( 'dashicons-x" onload="alert(1)', $enhanced_icon ), 'an injected attribute is rejected' );
assert( '' === $enhanced->sanitize( 'javascript:alert(1)', $enhanced_icon ), 'a javascript URL is rejected' );

echo "types-check: OK\n";
