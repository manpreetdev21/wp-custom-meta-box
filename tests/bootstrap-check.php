<?php
/**
 * Standalone self-check for the container and module boot pipeline.
 *
 * Runs without WordPress or PHPUnit: `php tests/bootstrap-check.php`.
 * Phase 12 replaces this with the full PHPUnit + WP test suite.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

// Autoloader plus minimal WordPress shims for the classes under test.
require_once __DIR__ . '/shims.php';

use WPCMB\Abstracts\Module;
use WPCMB\Container;

/** Module that records its own boot. */
final class BootedSpy extends Module {
	/** @var bool */
	public static bool $booted = false;

	/** Record the boot. */
	public function boot(): void {
		self::$booted = true;
	}
}

/** Module that must never boot. */
final class DisabledSpy extends Module {
	/** @var bool */
	public static bool $booted = false;

	/** Disabled. */
	public function is_enabled(): bool {
		return false;
	}

	/** Record the boot. */
	public function boot(): void {
		self::$booted = true;
	}
}

$container = new Container();

// Factories are lazy and resolve once.
$calls = 0;
$container->set(
	'lazy',
	static function () use ( &$calls ) {
		++$calls;
		return new stdClass();
	}
);
assert( 0 === $calls, 'factory must not run before get()' );
assert( $container->get( 'lazy' ) === $container->get( 'lazy' ), 'factory result must be shared' );
assert( 1 === $calls, 'factory must run exactly once' );

// Shared instances win, has() reports both kinds, unknown ids throw.
$shared = new stdClass();
$container->share( 'shared', $shared );
assert( $container->get( 'shared' ) === $shared, 'share() must return the same instance' );
assert( $container->has( 'shared' ) && $container->has( 'lazy' ), 'has() must see both kinds' );
assert( ! $container->has( 'nope' ), 'has() must not invent services' );

$threw = false;
try {
	$container->get( 'not-a-class' );
} catch ( InvalidArgumentException ) {
	$threw = true;
}
assert( $threw, 'unknown non-class id must throw' );

// Auto-wiring hands modules the container.
$module = $container->get( BootedSpy::class );
assert( $module instanceof BootedSpy, 'modules must auto-wire' );

// Boot pipeline: enabled modules boot, disabled ones do not, boot runs once.
$plugin = WPCMB\Plugin::instance();
wpcmb_test_filter( 'wpcmb/modules', static fn() => array( BootedSpy::class, DisabledSpy::class, 'NotAModule' ) );
$plugin->boot();
assert( BootedSpy::$booted, 'enabled module must boot' );
assert( ! DisabledSpy::$booted, 'disabled module must not boot' );
assert( $plugin->module( BootedSpy::class ) instanceof BootedSpy, 'booted module must be retrievable' );
assert( null === $plugin->module( DisabledSpy::class ), 'skipped module must not be retrievable' );

BootedSpy::$booted = false;
$plugin->boot();
assert( ! BootedSpy::$booted, 'boot() must be idempotent' );

echo "bootstrap-check: OK\n";
