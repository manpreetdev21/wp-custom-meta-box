<?php
/**
 * Revision and autosave support for field values.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Database;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Declares which meta keys WordPress should revision.
 *
 * WordPress 6.4+ already stores, restores, diffs and autosaves any meta key
 * returned by `wp_post_revision_meta_keys`. Naming the plugin's value keys
 * there buys revision history, revision restore, revision diffs in the editor
 * and autosave recovery in one filter — none of which is worth reimplementing
 * against a private revision table.
 */
final class Revisions extends Module {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'wp_post_revision_meta_keys', array( $this, 'meta_keys' ), 10, 2 );
	}

	/**
	 * Add the plugin's meta keys to the revisioned set for a post type.
	 *
	 * Every field name is offered for every post type rather than resolving
	 * which groups apply where: a key with no stored value is a no-op to
	 * core, and evaluating location rules on this filter would run on every
	 * post save for no observable difference.
	 *
	 * @param array<int, string> $keys      Revisioned meta keys.
	 * @param string             $post_type Post type being revisioned.
	 *
	 * @return array<int, string>
	 */
	public function meta_keys( $keys, $post_type = '' ): array {
		$keys = is_array( $keys ) ? $keys : array();

		if ( FieldGroupPostType::POST_TYPE === $post_type ) {
			return $keys;
		}

		return array_values( array_unique( array_merge( $keys, $this->container->get( Repository::class )->field_names() ) ) );
	}
}
