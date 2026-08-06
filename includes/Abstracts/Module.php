<?php
/**
 * Base module.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Abstracts;

use WPCMB\Container;
use WPCMB\Interfaces\Bootable;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for plugin modules.
 *
 * Holds the container and provides the `is_enabled()` gate every module is
 * filtered through, so site owners can switch whole features off.
 */
abstract class Module implements Bootable {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	protected Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Whether this module should boot on the current request.
	 *
	 * Override to skip work on requests the module has no business in, e.g.
	 * an admin-only module returning `is_admin()`.
	 */
	public function is_enabled(): bool {
		return true;
	}
}
