<?php
/**
 * Enforce the admin UI isolation rules.
 *
 * The plugin must never restyle WordPress or another plugin. That is a
 * promise no amount of care keeps on its own — one unscoped selector added
 * in a hurry breaks it silently, on somebody else's screen. This checks the
 * shipped CSS and JS for it instead.
 *
 * Run with `php tests/ui-check.php`.
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
 * Every selector in a stylesheet, with its source line.
 *
 * @param string $css  Stylesheet contents.
 * @param string $file File name for reporting.
 *
 * @return array<int, array{selector: string, line: int, file: string}>
 */
function wpcmb_selectors( string $css, string $file ): array {
	// Strip comments so a selector mentioned in prose is not treated as code.
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

	$selectors = array();
	$line      = 1;
	$buffer    = '';
	$depth     = 0;

	foreach ( str_split( $css ) as $character ) {
		if ( "\n" === $character ) {
			++$line;
		}

		if ( '{' === $character ) {
			$text = trim( $buffer );
			$buffer = '';

			++$depth;

			// An at-rule opens a block whose contents are selectors of their
			// own, so only the inner level is checked.
			if ( '' === $text || str_starts_with( $text, '@' ) ) {
				continue;
			}

			foreach ( explode( ',', $text ) as $selector ) {
				$selector = trim( preg_replace( '/\s+/', ' ', $selector ) ?? '' );

				if ( '' !== $selector ) {
					$selectors[] = array( 'selector' => $selector, 'line' => $line, 'file' => $file );
				}
			}

			continue;
		}

		if ( '}' === $character ) {
			--$depth;
			$buffer = '';

			continue;
		}

		if ( ';' === $character && 0 === $depth ) {
			$buffer = '';

			continue;
		}

		$buffer .= $character;
	}

	return $selectors;
}

$wpcmb_css_files = glob( $wpcmb_root . '/assets/css/*.css' ) ?: array();
$wpcmb_js_files  = glob( $wpcmb_root . '/assets/js/*.js' ) ?: array();

echo "CSS isolation\n";

/*
 * Rule 1: every selector is anchored to something of ours.
 *
 * The brief asks for `.wpcmb-admin` or `#wpcmb-app` specifically. That is
 * right for the plugin's own screens but wrong for the field styles, which
 * render inside the post, term, user and comment editors where the body
 * carries no class of ours. Any `wpcmb-`-prefixed class in the first
 * compound is equally airtight — it cannot match markup we did not write —
 * so that is what is enforced.
 */
$wpcmb_unanchored = array();

foreach ( $wpcmb_css_files as $wpcmb_file ) {
	foreach ( wpcmb_selectors( (string) file_get_contents( $wpcmb_file ), basename( $wpcmb_file ) ) as $wpcmb_entry ) {
		$first = explode( ' ', $wpcmb_entry['selector'] )[0];
		$first = explode( '>', $first )[0];

		if ( 1 === preg_match( '/(^|\.)wpcmb-|#wpcmb-/', $first ) ) {
			continue;
		}

		// `:root`-style hooks and keyframe stops are not element selectors.
		if ( 1 === preg_match( '/^(from|to|\d+%)$/', $first ) ) {
			continue;
		}

		$wpcmb_unanchored[] = sprintf( '%s:%d  %s', $wpcmb_entry['file'], $wpcmb_entry['line'], $wpcmb_entry['selector'] );
	}
}

wpcmb_rule( 'every selector starts with a wpcmb- class or id', $wpcmb_unanchored );

/*
 * Rule 2: core and third-party classes are never styled, even scoped.
 *
 * Scoping keeps the damage inside our own screens, but restyling `.button`
 * under `.wpcmb-admin` still means a core control looks different here than
 * everywhere else in the admin, which is its own kind of wrong.
 */
$wpcmb_reserved = array(
	'button',
	'notice',
	'postbox',
	'wrap',
	'form-table',
	'widefat',
	'submit',
	'wp-core-ui',
	'dashicons',
);

$wpcmb_restyled = array();

foreach ( $wpcmb_css_files as $wpcmb_file ) {
	foreach ( wpcmb_selectors( (string) file_get_contents( $wpcmb_file ), basename( $wpcmb_file ) ) as $wpcmb_entry ) {
		foreach ( $wpcmb_reserved as $wpcmb_class ) {
			// The final compound is what the rule actually paints.
			$parts = preg_split( '/[\s>+~]+/', $wpcmb_entry['selector'] ) ?: array();
			$last  = (string) end( $parts );

			if ( 1 === preg_match( '/\.' . preg_quote( $wpcmb_class, '/' ) . '(?![\w-])/', $last ) ) {
				$wpcmb_restyled[] = sprintf(
					'%s:%d  %s  (paints .%s)',
					$wpcmb_entry['file'],
					$wpcmb_entry['line'],
					$wpcmb_entry['selector'],
					$wpcmb_class
				);
			}
		}
	}
}

wpcmb_rule( 'no core or third-party class is restyled', $wpcmb_restyled );

/* Rule 3: no bare element selector anywhere. */
$wpcmb_bare = array();

foreach ( $wpcmb_css_files as $wpcmb_file ) {
	foreach ( wpcmb_selectors( (string) file_get_contents( $wpcmb_file ), basename( $wpcmb_file ) ) as $wpcmb_entry ) {
		if ( 1 === preg_match( '/^[a-z][a-z0-9]*(\s*[,{]|$)/i', $wpcmb_entry['selector'] ) ) {
			$wpcmb_bare[] = sprintf( '%s:%d  %s', $wpcmb_entry['file'], $wpcmb_entry['line'], $wpcmb_entry['selector'] );
		}
	}
}

wpcmb_rule( 'no bare element selectors', $wpcmb_bare );

echo "\nMarkup and script isolation\n";

/* Rule 4: every data attribute the plugin writes is prefixed. */
$wpcmb_attrs = array();

$wpcmb_markup = array_merge(
	$wpcmb_js_files,
	glob( $wpcmb_root . '/includes/*.php' ) ?: array(),
	glob( $wpcmb_root . '/includes/*/*.php' ) ?: array(),
	glob( $wpcmb_root . '/templates/*.php' ) ?: array(),
	glob( $wpcmb_root . '/templates/*/*.php' ) ?: array()
);

foreach ( $wpcmb_markup as $wpcmb_file ) {
	$contents = (string) file_get_contents( $wpcmb_file );

	// Written attributes, and the `[data-x=` form used inside selectors.
	if ( preg_match_all( '/[\'"\s\[](data-([a-z0-9-]+))\s*[=\]]/i', $contents, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			if ( str_starts_with( $match[2], 'wpcmb-' ) ) {
				continue;
			}

			$wpcmb_attrs[] = sprintf( '%s  %s', basename( $wpcmb_file ), $match[1] );
		}
	}

	// Reads through the dataset API, which is the same attribute camelCased.
	if ( preg_match_all( '/\.dataset\.([A-Za-z][\w]*)/', $contents, $matches ) ) {
		foreach ( $matches[1] as $property ) {
			if ( str_starts_with( $property, 'wpcmb' ) ) {
				continue;
			}

			$wpcmb_attrs[] = sprintf( '%s  dataset.%s', basename( $wpcmb_file ), $property );
		}
	}
}

$wpcmb_attrs = array_values( array_unique( $wpcmb_attrs ) );

wpcmb_rule( 'every data attribute is prefixed data-wpcmb-', $wpcmb_attrs );

/* Rule 5: scripts leak nothing into the global scope beyond the one API. */
$wpcmb_globals = array();

foreach ( $wpcmb_js_files as $wpcmb_file ) {
	$contents = (string) file_get_contents( $wpcmb_file );

	if ( ! str_contains( $contents, "'use strict'" ) ) {
		$wpcmb_globals[] = sprintf( '%s  is not in strict mode', basename( $wpcmb_file ) );
	}

	if ( preg_match_all( '/^\s*(var|let|const|function)\s+([A-Za-z_$][\w$]*)/m', $contents, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			// Anything at column zero is outside the module wrapper.
			if ( 1 === preg_match( '/^(var|let|const|function)\s/', $match[0] ) ) {
				$wpcmb_globals[] = sprintf( '%s  declares %s at top level', basename( $wpcmb_file ), $match[2] );
			}
		}
	}

	if ( preg_match_all( '/window\.([A-Za-z_$][\w$]*)\s*=/', $contents, $matches ) ) {
		foreach ( $matches[1] as $name ) {
			if ( 'wpcmb' === $name ) {
				continue;
			}

			$wpcmb_globals[] = sprintf( '%s  assigns window.%s', basename( $wpcmb_file ), $name );
		}
	}
}

wpcmb_rule( 'scripts add nothing global but window.wpcmb', array_values( array_unique( $wpcmb_globals ) ) );

echo "\nAccessibility and internationalisation\n";

/* Rule 6: reduced motion is respected wherever motion is used. */
$wpcmb_motion = array();

foreach ( $wpcmb_css_files as $wpcmb_file ) {
	$contents = (string) file_get_contents( $wpcmb_file );
	$animates = 1 === preg_match( '/^\s*(transition|animation)\s*:/m', $contents );

	if ( $animates && ! str_contains( $contents, 'prefers-reduced-motion' ) ) {
		$wpcmb_motion[] = sprintf( '%s  animates without honouring prefers-reduced-motion', basename( $wpcmb_file ) );
	}
}

wpcmb_rule( 'motion respects prefers-reduced-motion', $wpcmb_motion );

/* Rule 7: nothing is laid out with physical left/right properties. */
$wpcmb_physical = array();

foreach ( $wpcmb_css_files as $wpcmb_file ) {
	$contents = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $wpcmb_file ) );
	$lines    = explode( "\n", $contents );

	foreach ( $lines as $number => $line ) {
		if ( 1 !== preg_match( '/^\s*(margin|padding|border)-(left|right)\s*:/', $line, $match ) ) {
			continue;
		}

		$wpcmb_physical[] = sprintf( '%s:%d  %s', basename( $wpcmb_file ), $number + 1, trim( $line ) );
	}
}

wpcmb_rule( 'layout uses logical properties, so RTL needs no second stylesheet', $wpcmb_physical );

printf( "\n%d of %d rules passed.\n", $wpcmb_passed, $wpcmb_passed + $wpcmb_failed );

exit( $wpcmb_failed > 0 ? 1 : 0 );
