<?php
/**
 * Run every PHP check.
 *
 * One command so the suite is actually run rather than remembered. Each check
 * is a separate process, because they assert on global state and a failure in
 * one must not take the rest down with it.
 *
 * Usage: php tests/run.php
 *
 * @package WPCMB
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions -- A developer-run CLI script.

$wpcmb_checks = glob( __DIR__ . '/*-check.php' );

if ( false === $wpcmb_checks || array() === $wpcmb_checks ) {
	fwrite( STDERR, "No checks found.\n" );
	exit( 1 );
}

sort( $wpcmb_checks );

$wpcmb_failed = 0;
$wpcmb_binary = PHP_BINARY;

foreach ( $wpcmb_checks as $wpcmb_check ) {
	$wpcmb_name = basename( $wpcmb_check, '.php' );

	// Assertions are compiled out by default in production builds of PHP, so
	// they are switched on explicitly here. Without this the checks would run
	// and pass while testing nothing at all.
	$wpcmb_command = sprintf(
		'%s -d zend.assertions=1 -d assert.exception=1 %s 2>&1',
		escapeshellarg( $wpcmb_binary ),
		escapeshellarg( $wpcmb_check )
	);

	$wpcmb_output = array();
	$wpcmb_status = 0;

	exec( $wpcmb_command, $wpcmb_output, $wpcmb_status );

	if ( 0 === $wpcmb_status ) {
		printf( "  ok    %s\n", $wpcmb_name );
		continue;
	}

	++$wpcmb_failed;

	printf( "  FAIL  %s\n", $wpcmb_name );

	foreach ( $wpcmb_output as $wpcmb_line ) {
		printf( "        %s\n", $wpcmb_line );
	}
}

$wpcmb_total = count( $wpcmb_checks );

printf(
	"\n%d of %d checks passed.\n",
	$wpcmb_total - $wpcmb_failed,
	$wpcmb_total
);

exit( $wpcmb_failed > 0 ? 1 : 0 );
