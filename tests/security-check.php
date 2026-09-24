<?php
/**
 * Enforce the plugin's authorisation rules.
 *
 * Every endpoint this plugin opens is a door onto somebody's site, and the
 * rules that keep those doors shut — a capability on every handler, a real
 * permission callback on every route, a nonce on every write — are the kind
 * that hold until the day somebody adds one more handler in a hurry. So they
 * are checked here rather than remembered, in the same spirit as
 * `tests/ui-check.php`.
 *
 * These are source rules, not behaviour: they cannot prove a capability is the
 * right one, only that a check is present and that the ones already reasoned
 * about have not quietly reverted. The behavioural half lives in
 * `tests/sanitize-check.php` and `tests/integration.php`.
 *
 * Run with `php tests/security-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions -- A developer-run CLI script.

$wpcmb_root   = dirname( __DIR__ );
$wpcmb_failed = 0;
$wpcmb_passed = 0;

/**
 * Report a rule's outcome.
 *
 * @param string            $rule       What was checked.
 * @param array<int,string> $violations Anything that broke it.
 */
function wpcmb_rule( string $rule, array $violations ): void {
	if ( array() === $violations ) {
		++$GLOBALS['wpcmb_passed'];
		printf( "  ok    %s\n", $rule );

		return;
	}

	++$GLOBALS['wpcmb_failed'];

	printf( "  FAIL  %s\n", $rule );

	foreach ( array_slice( $violations, 0, 12 ) as $violation ) {
		printf( "          %s\n", $violation );
	}

	if ( count( $violations ) > 12 ) {
		printf( "          … and %d more\n", count( $violations ) - 12 );
	}
}

/**
 * Read one of the plugin's files.
 *
 * @param string $relative Path below the plugin directory.
 */
function wpcmb_source( string $relative ): string {
	return (string) file_get_contents( $GLOBALS['wpcmb_root'] . '/' . $relative );
}

/**
 * The body of one PHP method, by name.
 *
 * Brace-counted from the signature rather than matched with a pattern, so a
 * method containing braces, strings or a closure is still read whole.
 *
 * @param string $source PHP source.
 * @param string $method Method name.
 */
function wpcmb_method_body( string $source, string $method ): string {
	$signature = preg_quote( ' function ' . $method . '(', '/' );

	if ( 1 !== preg_match( '/' . $signature . '/', $source, $match, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}

	$start = strpos( $source, '{', (int) $match[0][1] );

	if ( false === $start ) {
		return '';
	}

	$depth  = 0;
	$length = strlen( $source );

	for ( $i = $start; $i < $length; $i++ ) {
		if ( '{' === $source[ $i ] ) {
			++$depth;
		} elseif ( '}' === $source[ $i ] ) {
			--$depth;

			if ( 0 === $depth ) {
				return substr( $source, $start, $i - $start + 1 );
			}
		}
	}

	return '';
}

echo "Endpoint authorisation\n";

/*
 * Rule 1: every AJAX handler requires a capability.
 *
 * `wp_ajax_` runs for anyone signed in, subscribers included, so a handler
 * that checks only the nonce is open to the whole user table. Handlers may
 * check directly or through verified_field(), which is checked itself below.
 */
$wpcmb_ajax       = wpcmb_source( 'includes/Admin/Ajax.php' );
$wpcmb_uncapped   = array();
$wpcmb_handlers   = array();

if ( preg_match_all( "/add_action\(\s*'wp_ajax_[a-z_]+',\s*array\( \\\$this, '([a-z_]+)' \)/", $wpcmb_ajax, $matches ) ) {
	$wpcmb_handlers = $matches[1];
}

if ( array() === $wpcmb_handlers ) {
	$wpcmb_uncapped[] = 'no wp_ajax_ handlers found — has the registration moved?';
}

foreach ( $wpcmb_handlers as $wpcmb_handler ) {
	$body = wpcmb_method_body( $wpcmb_ajax, $wpcmb_handler );

	if ( '' === $body ) {
		$wpcmb_uncapped[] = sprintf( 'Ajax::%s()  could not be read', $wpcmb_handler );
		continue;
	}

	// Three legitimate forms: the capability directly, the shared
	// verified_field() gate, or Permissions, which is the plugin's one
	// answer to "may this user write this object" and calls
	// current_user_can() with the object in hand.
	$gated = str_contains( $body, 'current_user_can(' )
		|| str_contains( $body, 'verified_field()' )
		|| str_contains( $body, 'Permissions::can_edit(' );

	if ( ! $gated ) {
		$wpcmb_uncapped[] = sprintf( 'Ajax::%s()  checks no capability', $wpcmb_handler );
	}

	if ( ! str_contains( $body, 'check_ajax_referer(' ) && ! str_contains( $body, 'verified_field()' ) ) {
		$wpcmb_uncapped[] = sprintf( 'Ajax::%s()  checks no nonce', $wpcmb_handler );
	}
}

$wpcmb_verified = wpcmb_method_body( $wpcmb_ajax, 'verified_field' );

if ( ! str_contains( $wpcmb_verified, 'current_user_can(' ) ) {
	$wpcmb_uncapped[] = 'Ajax::verified_field()  checks no capability, so every handler through it is open';
}

wpcmb_rule( 'every AJAX handler checks a nonce and a capability', $wpcmb_uncapped );

/*
 * Rule 2: being signed in is not authorisation.
 *
 * `is_user_logged_in()` answers a different question from "may this person do
 * this", and the two have been confused here before. It stays legitimate in
 * the front-end form, which decides whether guests may post at all, so the
 * rule covers the endpoint files where it would mean authorisation.
 */
$wpcmb_login_gated = array();

foreach ( array( 'includes/Admin/Ajax.php', 'includes/REST/Controller.php', 'includes/REST/MetaRegistrar.php' ) as $wpcmb_file ) {
	$contents = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', wpcmb_source( $wpcmb_file ) );

	if ( str_contains( $contents, 'is_user_logged_in()' ) ) {
		$wpcmb_login_gated[] = sprintf( '%s  authorises with is_user_logged_in()', basename( $wpcmb_file ) );
	}
}

wpcmb_rule( 'no endpoint treats "signed in" as permission', $wpcmb_login_gated );

/*
 * Rule 3: every REST route has a real permission callback.
 *
 * A missing callback is a public route, and `__return_true` is a public route
 * written down. Counted per method entry so a second method added to an
 * existing route cannot inherit the first one's callback by sitting near it.
 */
$wpcmb_rest       = wpcmb_source( 'includes/REST/Controller.php' );
$wpcmb_unguarded  = array();
$wpcmb_methods    = substr_count( $wpcmb_rest, "'methods'" );
$wpcmb_callbacks  = substr_count( $wpcmb_rest, "'permission_callback'" );

if ( 0 === $wpcmb_methods ) {
	$wpcmb_unguarded[] = 'no routes found — has registration moved?';
}

if ( $wpcmb_methods !== $wpcmb_callbacks ) {
	$wpcmb_unguarded[] = sprintf( '%d route methods but %d permission callbacks', $wpcmb_methods, $wpcmb_callbacks );
}

if ( str_contains( $wpcmb_rest, '__return_true' ) ) {
	$wpcmb_unguarded[] = 'a permission callback is __return_true';
}

wpcmb_rule( 'every REST route has a permission callback that is not __return_true', $wpcmb_unguarded );

/*
 * Rule 4: term values follow the taxonomy's own capabilities.
 *
 * `manage_categories` is the category capability, not the taxonomy's. A
 * taxonomy registered with capabilities of its own would be writable by anyone
 * holding the category one. `edit_term` is a meta capability, so WordPress
 * maps it to whatever the taxonomy actually declared.
 */
$wpcmb_term_caps = array();

foreach ( array( 'includes/Fields/Permissions.php', 'includes/Admin/MetaBoxes.php' ) as $wpcmb_file ) {
	$contents = wpcmb_source( $wpcmb_file );

	if ( str_contains( $contents, "'manage_categories'" ) ) {
		$wpcmb_term_caps[] = sprintf( '%s  still uses manage_categories for term values', basename( $wpcmb_file ) );
	}

	if ( ! str_contains( $contents, "'edit_term'" ) ) {
		$wpcmb_term_caps[] = sprintf( '%s  no longer checks edit_term', basename( $wpcmb_file ) );
	}
}

wpcmb_rule( 'term values use the taxonomy-aware capability', $wpcmb_term_caps );

/*
 * Rule 5: every write path verifies a nonce.
 *
 * Each of these takes a POST and stores something. A nonce is what stops a
 * page elsewhere on the web from making somebody's browser do it for them.
 */
$wpcmb_unnonced = array();

$wpcmb_writers = array(
	'includes/Admin/MetaBoxes.php'       => 'save',
	'includes/Admin/FieldGroupEditor.php' => 'save',
	'includes/Admin/ToolsPage.php'       => 'handle_request',
	'includes/Frontend/Submission.php'   => 'process',
);

foreach ( $wpcmb_writers as $wpcmb_file => $wpcmb_method ) {
	$body = wpcmb_method_body( wpcmb_source( $wpcmb_file ), $wpcmb_method );

	if ( '' === $body ) {
		$wpcmb_unnonced[] = sprintf( '%s::%s()  could not be read', basename( $wpcmb_file ), $wpcmb_method );
		continue;
	}

	$checked = str_contains( $body, 'wp_verify_nonce(' )
		|| str_contains( $body, 'check_admin_referer(' )
		|| str_contains( $body, 'check_ajax_referer(' );

	if ( ! $checked ) {
		$wpcmb_unnonced[] = sprintf( '%s::%s()  verifies no nonce', basename( $wpcmb_file ), $wpcmb_method );
	}
}

wpcmb_rule( 'every write path verifies a nonce', $wpcmb_unnonced );

echo "\nStorage boundaries\n";

/*
 * Rule 6: field names cannot claim protected meta.
 *
 * The behaviour is asserted in sanitize-check.php; what matters here is that
 * the rule keeps living in one place. A second name sanitizer that forgets the
 * underscore would reopen it for whichever path uses that one instead.
 */
$wpcmb_names = array();

$wpcmb_group_source = wpcmb_source( 'includes/Fields/FieldGroup.php' );
$wpcmb_name_body    = wpcmb_method_body( $wpcmb_group_source, 'sanitize_field_name' );

if ( ! str_contains( $wpcmb_name_body, "ltrim(" ) || ! str_contains( $wpcmb_name_body, "'_'" ) ) {
	$wpcmb_names[] = 'FieldGroup::sanitize_field_name()  no longer strips leading underscores';
}

if ( substr_count( $wpcmb_group_source, 'function sanitize_field_name' ) > 1 ) {
	$wpcmb_names[] = 'FieldGroup  has more than one field-name sanitizer';
}

wpcmb_rule( 'field names cannot claim protected meta keys', $wpcmb_names );

/*
 * Rule 7: option-page values stay in the plugin's own namespace.
 *
 * Options are written by name, so an un-prefixed one would let a field called
 * `siteurl` overwrite the site's URL.
 */
$wpcmb_options = array();
$wpcmb_option  = wpcmb_method_body( wpcmb_source( 'includes/Fields/Values.php' ), 'option_name' );

if ( ! str_contains( $wpcmb_option, "'wpcmb_'" ) ) {
	$wpcmb_options[] = 'Values::option_name()  no longer prefixes option names';
}

wpcmb_rule( 'option-page values are written to prefixed option names', $wpcmb_options );

printf( "\n%d of %d rules passed.\n", $wpcmb_passed, $wpcmb_passed + $wpcmb_failed );

exit( $wpcmb_failed > 0 ? 1 : 0 );
