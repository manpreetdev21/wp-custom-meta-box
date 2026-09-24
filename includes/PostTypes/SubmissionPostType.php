<?php
/**
 * Form submission storage.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\PostTypes;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Gives every field group used as a form its own post type for submissions.
 *
 * One post type per group rather than one for all of them: submissions to a
 * contact form and submissions to a job application have different fields,
 * different columns and different people reading them, and a single shared
 * type would mix them into one list that suits neither.
 *
 * The name is the plugin's prefix, the group's own name, and four characters
 * of its key — `wpcmb_contact_1f4a`. WordPress allows twenty characters for a
 * post type and refuses to register anything longer, so the group name is cut
 * to fit and the key fragment keeps two groups with similar names apart. The
 * readable name lives in the labels, which is what anybody actually sees.
 *
 * A type is registered once it has something in it. Nothing needs registering
 * for a submission to be stored — `post_type` is only a string in a row — so
 * a group that has never been submitted adds no menu entry, and the first
 * submission makes one appear.
 */
final class SubmissionPostType extends Module {

	/**
	 * Option listing the submission post types in use, keyed by post type.
	 *
	 * Kept as an option rather than counted at runtime: the alternative is a
	 * query per group on every admin page load to answer a question whose
	 * answer only changes when a form is submitted.
	 */
	public const OPTION = 'wpcmb_submission_types';

	/**
	 * Meta key recording which group a submission came from.
	 */
	public const META_GROUP = '_wpcmb_group';

	/**
	 * Meta key recording the page the form was submitted from.
	 */
	public const META_SOURCE = '_wpcmb_source';

	/**
	 * The prefix every submission post type carries.
	 */
	private const PREFIX = 'wpcmb_';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register' ), 5 );
	}

	/**
	 * The post type a group's submissions are stored in.
	 *
	 * Derived, never stored, so it is the same answer everywhere and there is
	 * no second record to keep in step with the group.
	 *
	 * @param FieldGroup $group Field group.
	 */
	public static function post_type_for( FieldGroup $group ): string {
		$name = sanitize_key( str_replace( array( ' ', '-' ), '_', $group->title ) );
		$name = trim( (string) preg_replace( '/_+/', '_', $name ), '_' );

		if ( '' === $name ) {
			$name = 'form';
		}

		// Twenty characters all in: the prefix, nine of the name, and four of
		// the key, which is what keeps "Contact" and "Contact Us" apart.
		$fragment = substr( md5( $group->key ), 0, 4 );

		return self::PREFIX . substr( $name, 0, 9 ) . '_' . $fragment;
	}

	/**
	 * Record that a post type now holds submissions, and register it.
	 *
	 * Called when a submission is stored. The type is registered immediately
	 * as well as remembered, because the request that creates the first one
	 * carries on afterwards — the notification, the redirect and any
	 * `wpcmb/form/submitted` listener all expect a real post type.
	 *
	 * @param string $post_type Submission post type.
	 * @param string $group_key Field group key.
	 */
	public static function remember( string $post_type, string $group_key ): void {
		$types = self::recorded();

		if ( ( $types[ $post_type ] ?? '' ) === $group_key ) {
			return;
		}

		$types[ $post_type ] = $group_key;

		update_option( self::OPTION, $types, false );
	}

	/**
	 * The submission post types in use, keyed by post type.
	 *
	 * @return array<string, string>
	 */
	public static function recorded(): array {
		$types = get_option( self::OPTION, array() );

		return is_array( $types ) ? array_filter( $types, 'is_string' ) : array();
	}

	/**
	 * Whether a post type is a submission type that holds something.
	 *
	 * @param string $post_type Post type name.
	 */
	public static function is_submission_type( string $post_type ): bool {
		return isset( self::recorded()[ $post_type ] );
	}

	/**
	 * Whether a post type name is one this class would hand out.
	 *
	 * Asked before a type is remembered, so it cannot be answered by looking
	 * at what has been remembered already.
	 *
	 * @param string $post_type Post type name.
	 */
	public static function owns( string $post_type ): bool {
		return str_starts_with( $post_type, self::PREFIX )
			&& FieldGroupPostType::POST_TYPE !== $post_type;
	}

	/**
	 * Register a post type for every group that has submissions.
	 */
	public function register(): void {
		$repository = $this->container->get( Repository::class );

		foreach ( self::recorded() as $post_type => $group_key ) {
			$group = $repository->get( $group_key );
			$title = $group instanceof FieldGroup && '' !== $group->title
				? $group->title
				: __( 'Form', 'wp-custom-meta-box' );

			$this->register_one( $post_type, $title );
		}
	}

	/**
	 * Register one submission post type.
	 *
	 * Not public and not queryable: a submission is a record, not content, and
	 * nothing about it belongs on the front of the site. `create_posts` is
	 * denied outright — submissions arrive from forms, and an Add New button
	 * would offer an empty record with no form behind it.
	 *
	 * @param string $post_type Post type name.
	 * @param string $title     Group title, used for the labels.
	 */
	private function register_one( string $post_type, string $title ): void {
		$labels = array(
			/* translators: %s: field group title. */
			'name'          => sprintf( __( '%s submissions', 'wp-custom-meta-box' ), $title ),
			/* translators: %s: field group title. */
			'singular_name' => sprintf( __( '%s submission', 'wp-custom-meta-box' ), $title ),
			'menu_name'     => $title,
			/* translators: %s: field group title. */
			'edit_item'     => sprintf( __( '%s submission', 'wp-custom-meta-box' ), $title ),
			'search_items'  => __( 'Search submissions', 'wp-custom-meta-box' ),
			'not_found'     => __( 'No submissions yet.', 'wp-custom-meta-box' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => 'wpcmb',
			'show_in_rest'        => false,
			'hierarchical'        => false,
			'supports'            => array( 'title' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
		);

		/**
		 * Filters the arguments a submission post type is registered with.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, mixed> $args      Registration arguments.
		 * @param string               $post_type Post type name.
		 */
		register_post_type( $post_type, (array) apply_filters( 'wpcmb/submissions/post_type_args', $args, $post_type ) );
	}
}
