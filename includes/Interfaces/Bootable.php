<?php
/**
 * Bootable contract.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Interfaces;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by every plugin module.
 *
 * The boot() method is the single place a module registers its hooks. It runs
 * once, on `plugins_loaded`, after the container is populated.
 */
interface Bootable {

	/**
	 * Register hooks and wire the module into WordPress.
	 */
	public function boot(): void;
}
