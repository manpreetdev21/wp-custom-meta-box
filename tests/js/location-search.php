<?php
/**
 * Show what the location rule search returns for this site.
 *
 * Usage: php tests/js/location-search.php [param] [search]
 *
 * @package WPCMB
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- A developer-run CLI script.

define( 'WP_ADMIN', true );
require_once dirname( __DIR__, 5 ) . '/wp-load.php';

wp_set_current_user( 1 );

$wpcmb_param  = (string) ( $argv[1] ?? '' );
$wpcmb_search = (string) ( $argv[2] ?? '' );

$wpcmb_params = WPCMB\Fields\Locations::object_params();

if ( '' === $wpcmb_param ) {
	echo "Searchable rule parameters:\n";

	foreach ( $wpcmb_params as $wpcmb_name => $wpcmb_config ) {
		printf( "  %-14s %s\n", $wpcmb_name, $wpcmb_config['label'] );
	}

	echo "\nRun again with a parameter name to search it.\n";
	exit( 0 );
}

if ( ! isset( $wpcmb_params[ $wpcmb_param ] ) ) {
	fwrite( STDERR, "\"{$wpcmb_param}\" is not searchable.\n" );
	exit( 1 );
}

$wpcmb_results = WPCMB\Fields\Locations::search( $wpcmb_param, $wpcmb_search );

printf( "%s%s: %d result(s)\n\n", $wpcmb_param, '' !== $wpcmb_search ? " ~ \"{$wpcmb_search}\"" : '', count( $wpcmb_results ) );

foreach ( $wpcmb_results as $wpcmb_result ) {
	printf( "  %-8s %s\n", $wpcmb_result['value'], $wpcmb_result['label'] );
}
