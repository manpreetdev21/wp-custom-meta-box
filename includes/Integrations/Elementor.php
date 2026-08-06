<?php
/**
 * Elementor integration.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Integrations;

use WPCMB\Abstracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a dynamic tag that returns formatted field values.
 *
 * Elementor's built-in "Post Custom Field" tag already reaches these values,
 * because they are stored in native meta. What it returns is the raw value —
 * an attachment id for an image, a serialised array for a link. This tag
 * returns what the field type says the value means.
 *
 * The tag class extends an Elementor base class, so it cannot be declared
 * until Elementor has loaded. It lives in its own file, required at the
 * moment Elementor asks for tags, which is the only point at which that base
 * class is guaranteed to exist.
 */
final class Elementor extends Module {

	/**
	 * Dynamic tag group name.
	 */
	public const GROUP = 'wpcmb';

	/**
	 * Only load when Elementor is active.
	 */
	public function is_enabled(): bool {
		return did_action( 'elementor/loaded' ) > 0;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'elementor/dynamic_tags/register', array( $this, 'register_tags' ) );
	}

	/**
	 * Register the tag group and the tag itself.
	 *
	 * @param mixed $tags Elementor's dynamic tags manager.
	 */
	public function register_tags( $tags ): void {
		if ( ! is_object( $tags ) || ! method_exists( $tags, 'register' ) ) {
			return;
		}

		if ( method_exists( $tags, 'register_group' ) ) {
			$tags->register_group(
				self::GROUP,
				array( 'title' => __( 'Custom Meta Box', 'wp-custom-meta-box' ) )
			);
		}

		/*
		 * Deliberately not named after the class it defines, and excluded
		 * from Composer's classmap: the class inside extends an Elementor
		 * base class, so if the autoloader could reach it, any stray mention
		 * of the class name would fatal on a site without Elementor.
		 */
		require_once __DIR__ . '/elementor-tag.php';

		// The `false` skips the autoloader, for the same reason.
		if ( ! class_exists( ElementorTag::class, false ) ) {
			return;
		}

		$tags->register( new ElementorTag() );
	}
}
