<?php
/**
 * Plugin Name:       WP Custom Meta Box
 * Plugin URI:        https://github.com/manpreetdev21/wp-custom-meta-box
 * Description:       Field groups, meta boxes and a developer-friendly field API for WordPress.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Manpreet Singh
 * Author URI:        https://github.com/manpreetdev21/wp-custom-meta-box
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-custom-meta-box
 * Domain Path:       /languages
 *
 * @package WPCMB
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'WPCMB_VERSION', '1.0.0' );
define( 'WPCMB_FILE', __FILE__ );
define( 'WPCMB_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCMB_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCMB_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load the class autoloader.
 *
 * Prefers Composer's optimised autoloader, falls back to a PSR-4 loader so the
 * plugin also runs from a git checkout where `composer install` has not been run.
 */
if ( is_readable( WPCMB_DIR . 'vendor/autoload.php' ) ) {
	require_once WPCMB_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( ! str_starts_with( $class_name, 'WPCMB\\' ) ) {
				return;
			}
			$path = WPCMB_DIR . 'includes/' . str_replace( '\\', '/', substr( $class_name, 6 ) ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

/**
 * Public template functions.
 *
 * Required rather than autoloaded: these are plain functions, and themes
 * expect them to exist as soon as the plugin is loaded.
 */
require_once WPCMB_DIR . 'includes/API/functions.php';

register_activation_hook( __FILE__, array( WPCMB\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( WPCMB\Installer::class, 'deactivate' ) );

/**
 * Plugin instance accessor.
 *
 * @return WPCMB\Plugin
 */
function wpcmb(): WPCMB\Plugin {
	return WPCMB\Plugin::instance();
}

wpcmb()->init();
