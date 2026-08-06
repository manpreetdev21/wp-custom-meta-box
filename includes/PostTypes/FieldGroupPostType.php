<?php
/**
 * Field group storage.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\PostTypes;

use WPCMB\Abstracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type that stores field groups.
 *
 * Field groups live in `wp_posts`/`wp_postmeta` rather than custom tables.
 * WordPress already supplies the list table, search, pagination, sorting,
 * revisions, autosave, trash, capabilities, WXR import/export and the REST
 * plumbing for a post type. A bespoke schema would reimplement all of it and
 * add a migration surface for no measurable gain at field-group scale
 * (tens to low hundreds of rows, read once per screen and cached).
 */
final class FieldGroupPostType extends Module {

	/**
	 * Post type name.
	 */
	public const POST_TYPE = 'wpcmb_field_group';

	/**
	 * Meta key holding the JSON-encoded group configuration.
	 */
	public const META_CONFIG = '_wpcmb_config';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register' ), 5 );
		add_filter( 'wp_post_revision_meta_keys', array( $this, 'revision_meta_keys' ), 10, 2 );
	}

	/**
	 * The capability required to administer field groups.
	 *
	 * Every field-group capability maps to this one, so no role is ever
	 * modified: nothing to grant on activation, nothing to clean up on
	 * uninstall, and no way for a stale capability to outlive the plugin.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability required to manage field groups.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'wpcmb/capability', 'manage_options' );
	}

	/**
	 * Post type capability map, every entry pointing at capability().
	 *
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		$cap  = self::capability();
		$keys = array(
			'edit_post',
			'read_post',
			'delete_post',
			'edit_posts',
			'edit_others_posts',
			'delete_posts',
			'delete_others_posts',
			'publish_posts',
			'read_private_posts',
			'create_posts',
		);

		return array_fill_keys( $keys, $cap );
	}

	/**
	 * Register the post type.
	 */
	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'           => array(
					'name'               => __( 'Field Groups', 'wp-custom-meta-box' ),
					'singular_name'      => __( 'Field Group', 'wp-custom-meta-box' ),
					'add_new_item'       => __( 'Add Field Group', 'wp-custom-meta-box' ),
					'edit_item'          => __( 'Edit Field Group', 'wp-custom-meta-box' ),
					'new_item'           => __( 'New Field Group', 'wp-custom-meta-box' ),
					'search_items'       => __( 'Search Field Groups', 'wp-custom-meta-box' ),
					'not_found'          => __( 'No field groups found.', 'wp-custom-meta-box' ),
					'not_found_in_trash' => __( 'No field groups in Trash.', 'wp-custom-meta-box' ),
				),
				'public'           => false,
				'show_ui'          => true,
				'show_in_menu'     => 'wpcmb',
				'show_in_rest'     => false,
				'hierarchical'     => false,
				'supports'         => array( 'title', 'revisions' ),
				'capabilities'     => self::capabilities(),
				'map_meta_cap'     => false,
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => true,
				'delete_with_user' => false,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_CONFIG,
			array(
				'type'          => 'string',
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => false,
				'auth_callback' => static fn(): bool => current_user_can( self::capability() ),
			)
		);
	}

	/**
	 * Include the group configuration in post revisions.
	 *
	 * WordPress 6.4+ revisions the meta keys returned here, which is why the
	 * whole group config is a single meta value.
	 *
	 * @param array<int, string> $keys      Revisioned meta keys.
	 * @param string             $post_type Post type being revisioned.
	 *
	 * @return array<int, string>
	 */
	public function revision_meta_keys( $keys, $post_type = '' ): array {
		$keys = is_array( $keys ) ? $keys : array();

		if ( self::POST_TYPE !== $post_type ) {
			return $keys;
		}

		$keys[] = self::META_CONFIG;

		return $keys;
	}
}
