<?php
/**
 * Activation and deactivation routines.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on activation and deactivation.
 *
 * Static because register_activation_hook() fires outside the plugin's normal
 * lifecycle, before the container exists.
 */
final class Installer {

	/**
	 * Option holding the installed schema/plugin version.
	 */
	public const VERSION_OPTION = 'wpcmb_version';

	/**
	 * Option holding the first-install timestamp.
	 */
	public const INSTALLED_OPTION = 'wpcmb_installed_at';

	/**
	 * Activation handler.
	 */
	public static function activate(): void {
		if ( ! get_option( self::INSTALLED_OPTION ) ) {
			add_option( self::INSTALLED_OPTION, time(), '', false );
		}

		update_option( self::VERSION_OPTION, WPCMB_VERSION, false );

		/**
		 * Fires on plugin activation, after core options are written.
		 *
		 * Schema creation (phase 3) and rewrite-dependent registrations hook here.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wpcmb/activate' );

		flush_rewrite_rules();
	}

	/**
	 * Deactivation handler.
	 *
	 * Data is left intact; removal happens in uninstall.php.
	 */
	public static function deactivate(): void {
		/**
		 * Fires on plugin deactivation.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wpcmb/deactivate' );

		flush_rewrite_rules();
	}
}
