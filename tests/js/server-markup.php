<?php
/**
 * Print what the field group editor actually sends to the browser.
 *
 * A diagnostic for "my fields are not showing": it separates a server problem
 * from a browser one by showing the state the page hands to builder.js.
 *
 * Usage: php tests/js/server-markup.php <field-group-post-id>
 *
 * @package WPCMB
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- A developer-run CLI script.

define( 'WP_ADMIN', true );
require_once dirname( __DIR__, 5 ) . '/wp-load.php';

wp_set_current_user( 1 );

$wpcmb_id   = (int) ( $argv[1] ?? 0 );
$wpcmb_post = get_post( $wpcmb_id );

if ( ! $wpcmb_post instanceof WP_Post ) {
	fwrite( STDERR, "No post {$wpcmb_id}.\n" );
	exit( 1 );
}

$wpcmb_editor = wpcmb()->module( WPCMB\Admin\FieldGroupEditor::class );

ob_start();
$wpcmb_editor->render_fields( $wpcmb_post );
$wpcmb_html = (string) ob_get_clean();

printf( "meta box bytes    : %d\n", strlen( $wpcmb_html ) );
printf( "list container    : %s\n", str_contains( $wpcmb_html, 'data-wpcmb-list' ) ? 'present' : 'MISSING' );

if ( ! preg_match( '/<textarea[^>]*name="wpcmb_fields_json"[^>]*>(.*?)<\/textarea>/s', $wpcmb_html, $wpcmb_match ) ) {
	echo "state textarea    : MISSING\n";
	exit( 1 );
}

$wpcmb_json   = html_entity_decode( $wpcmb_match[1], ENT_QUOTES );
$wpcmb_fields = json_decode( $wpcmb_json, true );

printf( "state JSON        : %s\n", is_array( $wpcmb_fields ) ? 'valid' : 'INVALID' );

if ( ! is_array( $wpcmb_fields ) ) {
	exit( 1 );
}

printf( "fields in state   : %d\n", count( $wpcmb_fields ) );

foreach ( $wpcmb_fields as $wpcmb_index => $wpcmb_field ) {
	printf(
		"  [%d] %-12s %-18s subs=%-2s settings=%s conditional=%s\n",
		$wpcmb_index,
		$wpcmb_field['name'] ?? '?',
		$wpcmb_field['type'] ?? '?',
		isset( $wpcmb_field['sub_fields'] ) ? count( $wpcmb_field['sub_fields'] ) : '-',
		array() === ( $wpcmb_field['settings'] ?? null ) ? '[]' : 'object',
		array() === ( $wpcmb_field['conditional'] ?? null ) ? '[]' : 'object'
	);
}

printf( "\nbuilder.js version: %s\n", WPCMB\Admin\Assets::version( 'assets/js/builder.js' ) );
echo "\nAn empty [] for settings or conditional is what the browser script must\n";
echo "normalise. That is expected in the state; it is not a server fault.\n";
