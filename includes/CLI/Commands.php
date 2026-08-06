<?php
/**
 * WP-CLI registration.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\CLI;

use WPCMB\Abstracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `wp wpcmb` command.
 *
 * The command surface lives in its own class rather than on this module.
 * WP-CLI turns every public method of a command object into a subcommand, so
 * registering the module itself would publish `wp wpcmb boot` and
 * `wp wpcmb is_enabled` alongside the real ones.
 */
final class Commands extends Module {

	/**
	 * Only load under WP-CLI.
	 */
	public function is_enabled(): bool {
		return defined( 'WP_CLI' ) && \WP_CLI;
	}

	/**
	 * Register the command.
	 */
	public function boot(): void {
		\WP_CLI::add_command( 'wpcmb', new FieldCommand( $this->container ) );
	}
}
