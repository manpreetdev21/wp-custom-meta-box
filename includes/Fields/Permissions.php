<?php
/**
 * Capability checks for field values.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Decides who may read and write an object's field values.
 *
 * One place, because three callers now need the same answer — the edit
 * screens, the front-end forms and the REST routes — and a capability check
 * that exists in three places is a capability check that will eventually
 * disagree with itself.
 *
 * Values inherit the permissions of the object they belong to. A field on a
 * post is readable by whoever may read that post and writable by whoever may
 * edit it; the plugin never invents a looser rule of its own.
 */
final class Permissions {

	/**
	 * Whether the current user may read an object's values.
	 *
	 * @param ObjectRef $ref Object reference.
	 */
	public static function can_read( ObjectRef $ref ): bool {
		if ( ! $ref->is_valid() ) {
			return false;
		}

		$allowed = match ( $ref->type ) {
			ObjectRef::POST    => self::can_read_post( (int) $ref->id ),
			ObjectRef::TERM    => true,
			ObjectRef::USER    => current_user_can( 'list_users' ) || get_current_user_id() === (int) $ref->id,
			ObjectRef::COMMENT => self::can_read_comment( (int) $ref->id ),
			ObjectRef::OPTION  => current_user_can( 'manage_options' ),
			default            => false,
		};

		/**
		 * Filters whether the current user may read an object's field values.
		 *
		 * @since 1.0.0
		 *
		 * @param bool      $allowed Whether reading is allowed.
		 * @param ObjectRef $ref     Object reference.
		 */
		return (bool) apply_filters( 'wpcmb/permissions/read', $allowed, $ref );
	}

	/**
	 * Whether the current user may write an object's values.
	 *
	 * @param ObjectRef $ref Object reference.
	 */
	public static function can_edit( ObjectRef $ref ): bool {
		if ( ! $ref->is_valid() ) {
			return false;
		}

		$allowed = match ( $ref->type ) {
			ObjectRef::POST    => current_user_can( 'edit_post', (int) $ref->id ),
			ObjectRef::TERM    => current_user_can( 'manage_categories' ),
			ObjectRef::USER    => current_user_can( 'edit_user', (int) $ref->id ),
			ObjectRef::COMMENT => current_user_can( 'edit_comment', (int) $ref->id ),
			ObjectRef::OPTION  => current_user_can( 'manage_options' ),
			default            => false,
		};

		/**
		 * Filters whether the current user may write an object's field values.
		 *
		 * @since 1.0.0
		 *
		 * @param bool      $allowed Whether writing is allowed.
		 * @param ObjectRef $ref     Object reference.
		 */
		return (bool) apply_filters( 'wpcmb/permissions/edit', $allowed, $ref );
	}

	/**
	 * Whether a post's values are readable.
	 *
	 * A published post is public, so its values are too. Anything else needs
	 * the capability to read that specific post.
	 *
	 * @param int $post_id Post id.
	 */
	private static function can_read_post( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( 'publish' === $post->post_status ) {
			return true;
		}

		return current_user_can( 'read_post', $post_id );
	}

	/**
	 * Whether a comment's values are readable.
	 *
	 * @param int $comment_id Comment id.
	 */
	private static function can_read_comment( int $comment_id ): bool {
		$comment = get_comment( $comment_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return false;
		}

		return '1' === $comment->comment_approved || current_user_can( 'moderate_comments' );
	}
}
