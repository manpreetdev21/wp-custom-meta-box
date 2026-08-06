<?php
/**
 * Standalone self-check for the integration modules.
 *
 * The integrations themselves cannot be exercised without WooCommerce,
 * Elementor, Bricks or WP-CLI installed. What can be checked without them is
 * the part that matters most on a site that does not have them: that every
 * module stays switched off, registers nothing, and cannot fatal.
 *
 * Run with `php tests/integrations-check.php`.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

require_once __DIR__ . '/shims.php';

use WPCMB\Container;
use WPCMB\Integrations\Bricks;
use WPCMB\Integrations\Elementor;
use WPCMB\Integrations\Shortcodes;
use WPCMB\Integrations\WooCommerce;
use WPCMB\CLI\Commands;

$container = new Container();

/* -------------------------------------------------------------------------
 * Every optional integration is off when its host is absent.
 *
 * This is the whole safety story for a plugin that ships four integrations:
 * a site with none of them installed must not load any of their code, and a
 * module whose is_enabled() misjudged that would fatal on boot.
 * ---------------------------------------------------------------------- */

$optional = array(
	'WooCommerce' => new WooCommerce( $container ),
	'Elementor'   => new Elementor( $container ),
	'Bricks'      => new Bricks( $container ),
	'WP-CLI'      => new Commands( $container ),
);

foreach ( $optional as $host => $module ) {
	assert( ! $module->is_enabled(), "the {$host} integration must stay off when {$host} is absent" );
}

// Shortcodes depend on nothing, so they are always on.
assert( ( new Shortcodes( $container ) )->is_enabled(), 'shortcodes need no host plugin' );

/* -------------------------------------------------------------------------
 * The Elementor tag file must be unreachable by the autoloader.
 *
 * It extends an Elementor base class. If the autoloader could find it, any
 * mention of the class name on a site without Elementor would be a fatal
 * error rather than a missing feature.
 * ---------------------------------------------------------------------- */

$includes = dirname( __DIR__ ) . '/includes';

assert(
	! file_exists( $includes . '/Integrations/ElementorTag.php' ),
	'the Elementor tag must not sit at the path its class name implies'
);
assert(
	file_exists( $includes . '/Integrations/elementor-tag.php' ),
	'the Elementor tag file is where the loader expects it'
);
assert(
	! class_exists( 'WPCMB\Integrations\ElementorTag' ),
	'mentioning the Elementor tag class must not load or fatal'
);

// Composer must be told to leave it out of the classmap too, or an optimised
// autoloader would find it by scanning rather than by path.
$composer = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/composer.json' ), true );

assert(
	in_array( 'includes/Integrations/elementor-tag.php', $composer['autoload']['exclude-from-classmap'] ?? array(), true ),
	'the Elementor tag file is excluded from the classmap'
);

/* -------------------------------------------------------------------------
 * The CLI surface holds nothing but subcommands.
 *
 * WP-CLI turns every public method of a command object into a subcommand, so
 * a stray helper left public becomes a documented command.
 * ---------------------------------------------------------------------- */

$methods = array_map(
	static fn( ReflectionMethod $m ): string => $m->getName(),
	( new ReflectionClass( 'WPCMB\CLI\FieldCommand' ) )->getMethods( ReflectionMethod::IS_PUBLIC )
);

sort( $methods );

assert(
	array( '__construct', 'delete', 'export', 'get', 'groups', 'import', 'update' ) === $methods,
	'the CLI class exposes only its subcommands, found: ' . implode( ', ', $methods )
);

// And the module that registers it must not be the command object itself.
assert(
	! method_exists( 'WPCMB\CLI\Commands', 'export' ),
	'the registering module is not the command surface'
);

/* -------------------------------------------------------------------------
 * The storage seam the WooCommerce integration relies on exists.
 * ---------------------------------------------------------------------- */

$values = file_get_contents( $includes . '/Fields/Values.php' );

foreach ( array( 'wpcmb/storage/read', 'wpcmb/storage/write', 'wpcmb/storage/delete' ) as $hook ) {
	assert( str_contains( (string) $values, $hook ), "the {$hook} filter exists for integrations to claim storage" );
}

echo "integrations-check: OK\n";
