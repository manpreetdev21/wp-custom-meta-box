<?php
/**
 * Location rule catalogue.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Describes where a field group may appear.
 *
 * Owns the catalogue — which rule parameters exist and what each may be
 * compared against — and evaluates a group's rules against a Context.
 */
final class Locations {

	/**
	 * Cache key for the catalogue, per locale.
	 */
	private const CACHE_GROUP = 'wpcmb';

	/**
	 * Rule parameters, grouped for the rule builder's optgroups.
	 *
	 * @return array<string, array{label: string, group: string}>
	 */
	public static function params(): array {
		$post  = __( 'Post', 'wp-custom-meta-box' );
		$page  = __( 'Page', 'wp-custom-meta-box' );
		$user  = __( 'User', 'wp-custom-meta-box' );
		$forms = __( 'Forms', 'wp-custom-meta-box' );
		$woo   = __( 'WooCommerce', 'wp-custom-meta-box' );

		$params = array(
			'post_type'         => array(
				'label' => __( 'Post Type', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-admin-post',
				'group' => $post,
			),
			'post_template'     => array(
				'label' => __( 'Post Template', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-layout',
				'group' => $post,
			),
			'post_status'       => array(
				'label' => __( 'Post Status', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-post-status',
				'group' => $post,
			),
			'post_format'       => array(
				'label' => __( 'Post Format', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-format-aside',
				'group' => $post,
			),
			'post_category'     => array(
				'label' => __( 'Post Category', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-category',
				'group' => $post,
			),
			'post_taxonomy'     => array(
				'label' => __( 'Post Taxonomy', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-tag',
				'group' => $post,
			),
			'post'              => array(
				'label' => __( 'Post', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-admin-post',
				'group' => $post,
			),
			'page_template'     => array(
				'label' => __( 'Page Template', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-layout',
				'group' => $page,
			),
			'page_type'         => array(
				'label' => __( 'Page Type', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-admin-page',
				'group' => $page,
			),
			'page_parent'       => array(
				'label' => __( 'Page Parent', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-networking',
				'group' => $page,
			),
			'page'              => array(
				'label' => __( 'Page', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-admin-page',
				'group' => $page,
			),
			'current_user'      => array(
				'label' => __( 'Current User', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-admin-users',
				'group' => $user,
			),
			'current_user_role' => array(
				'label' => __( 'Current User Role', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-groups',
				'group' => $user,
			),
			'user_form'         => array(
				'label' => __( 'User Form', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-id',
				'group' => $user,
			),
			'user_role'         => array(
				'label' => __( 'User Role', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-groups',
				'group' => $user,
			),
			'taxonomy'          => array(
				'label' => __( 'Taxonomy', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-tag',
				'group' => $forms,
			),
			'attachment'        => array(
				'label' => __( 'Attachment', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-admin-media',
				'group' => $forms,
			),
			'comment'           => array(
				'label' => __( 'Comment', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-admin-comments',
				'group' => $forms,
			),
			'nav_menu'          => array(
				'label' => __( 'Menu', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-menu',
				'group' => $forms,
			),
			'nav_menu_item'     => array(
				'label' => __( 'Menu Item', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-menu-alt3',
				'group' => $forms,
			),
			'widget'            => array(
				'label' => __( 'Widget', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-screenoptions',
				'group' => $forms,
			),
			'block'             => array(
				'label' => __( 'Block', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-block-default',
				'group' => $forms,
			),
			'options_page'      => array(
				'label' => __( 'Options Page', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-admin-generic',
				'group' => $forms,
			),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$params['wc_product_type'] = array(
				'label' => __( 'Product Type', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-cart',
				'group' => $woo,
			);
			$params['wc_order_status'] = array(
				'label' => __( 'Order Status', 'wp-custom-meta-box' ),
				'icon'  => 'dashicons-clipboard',
				'group' => $woo,
			);
		}

		/**
		 * Filters the available location rule parameters.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{label: string, group: string}> $params Rule parameters.
		 */
		return (array) apply_filters( 'wpcmb/location/params', $params );
	}

	/**
	 * Selectable values for every rule parameter.
	 *
	 * Built once per request and cached in the object cache for the rest of
	 * the page load: the rule builder needs all of them at once, and several
	 * involve term or post queries.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function choices(): array {
		$cached = wp_cache_get( 'location_choices', self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$choices = array(
			'post_type'         => self::post_types(),
			'post_status'       => self::labelled( get_post_stati( array(), 'objects' ), 'label' ),
			'post_format'       => get_post_format_strings(),
			'post_template'     => self::templates(),
			'page_template'     => self::templates( 'page' ),
			'page_type'         => array(
				'front_page' => __( 'Front Page', 'wp-custom-meta-box' ),
				'posts_page' => __( 'Posts Page', 'wp-custom-meta-box' ),
				'top_level'  => __( 'Top Level Page', 'wp-custom-meta-box' ),
				'parent'     => __( 'Parent Page', 'wp-custom-meta-box' ),
				'child'      => __( 'Child Page', 'wp-custom-meta-box' ),
			),
			'user_form'         => array(
				'add'      => __( 'Add User', 'wp-custom-meta-box' ),
				'edit'     => __( 'Edit User', 'wp-custom-meta-box' ),
				'register' => __( 'Register User', 'wp-custom-meta-box' ),
			),
			'taxonomy'          => self::taxonomies(),
			'post_taxonomy'     => self::taxonomies(),
			'current_user'      => array(
				'logged_in'     => __( 'Logged In', 'wp-custom-meta-box' ),
				'viewing_front' => __( 'Viewing Front End', 'wp-custom-meta-box' ),
				'viewing_back'  => __( 'Viewing Back End', 'wp-custom-meta-box' ),
			),
			'current_user_role' => self::roles(),
			'user_role'         => self::roles(),
			'attachment'        => array( 'all' => __( 'All', 'wp-custom-meta-box' ) ),
			'comment'           => self::post_types(),
			'nav_menu'          => self::nav_menus(),
			'nav_menu_item'     => array( 'all' => __( 'All', 'wp-custom-meta-box' ) ),
			'widget'            => self::widgets(),
			'block'             => array(),
			'options_page'      => array(),
			'post_category'     => self::terms( 'category' ),
		);

		/**
		 * Filters the selectable values for each location rule parameter.
		 *
		 * Parameters absent from this map fall back to a free-text input in
		 * the rule builder, which is how `post`, `page` and `page_parent`
		 * avoid loading every post on the site.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, string>> $choices Values keyed by parameter.
		 */
		$choices = (array) apply_filters( 'wpcmb/location/choices', $choices );

		wp_cache_set( 'location_choices', $choices, self::CACHE_GROUP, MINUTE_IN_SECONDS );

		return $choices;
	}

	/**
	 * Most objects to offer in one search.
	 */
	public const SEARCH_LIMIT = 50;

	/**
	 * Rule parameters whose value is a specific object on the site.
	 *
	 * These are not in choices() on purpose. A site can hold tens of
	 * thousands of posts, and putting them all in the page so the rule
	 * builder can show a dropdown would make the editor screen enormous for
	 * everyone, to serve a rule most groups never use. They are searched on
	 * demand instead.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function object_params(): array {
		$params = array(
			'post'          => array(
				'kind'      => 'post',
				'post_type' => 'any',
				'label'     => __( 'Search posts', 'wp-custom-meta-box' ),
			),
			'page'          => array(
				'kind'      => 'post',
				'post_type' => 'page',
				'label'     => __( 'Search pages', 'wp-custom-meta-box' ),
			),
			'page_parent'   => array(
				'kind'      => 'post',
				'post_type' => 'page',
				'label'     => __( 'Search pages', 'wp-custom-meta-box' ),
			),
			'post_category' => array(
				'kind'     => 'term',
				'taxonomy' => 'category',
				'label'    => __( 'Search categories', 'wp-custom-meta-box' ),
			),
		);

		/**
		 * Filters which rule parameters are searched rather than listed.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $params Searchable parameters.
		 */
		return (array) apply_filters( 'wpcmb/location/object_params', $params );
	}

	/**
	 * Search the objects a rule parameter can point at.
	 *
	 * @param string $param  Rule parameter.
	 * @param string $search Search term, empty for the most recent objects.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function search( string $param, string $search = '' ): array {
		$params = self::object_params();

		if ( ! isset( $params[ $param ] ) ) {
			return array();
		}

		$config  = $params[ $param ];
		$results = 'term' === ( $config['kind'] ?? '' )
			? self::search_terms( (string) $config['taxonomy'], $search )
			: self::search_posts( (string) ( $config['post_type'] ?? 'any' ), $search );

		/**
		 * Filters the results of a location rule object search.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array{value: string, label: string}> $results Results.
		 * @param string                                          $param   Rule parameter.
		 * @param string                                          $search  Search term.
		 */
		return (array) apply_filters( 'wpcmb/location/search', $results, $param, $search );
	}

	/**
	 * Search posts.
	 *
	 * @param string $post_type Post type, or `any`.
	 * @param string $search    Search term.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function search_posts( string $post_type, string $search ): array {
		$args = array(
			'post_type'              => 'any' === $post_type ? self::searchable_post_types() : $post_type,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => self::SEARCH_LIMIT,
			'orderby'                => '' !== $search ? 'relevance' : 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$results = array();

		foreach ( get_posts( $args ) as $post ) {
			$type = get_post_type_object( $post->post_type );

			$results[] = array(
				'value' => (string) $post->ID,
				'label' => sprintf(
					/* translators: 1: post title, 2: post type label, 3: post id. */
					__( '%1$s — %2$s (#%3$d)', 'wp-custom-meta-box' ),
					self::plain_title( $post->post_title ),
					$type instanceof \WP_Post_Type ? $type->labels->singular_name : $post->post_type,
					$post->ID
				),
			);
		}

		return $results;
	}

	/**
	 * A title as a person would read it.
	 *
	 * Titles are stored with HTML entities, and these labels are put into a
	 * dropdown as text rather than as markup — so "Terms &amp; Conditions"
	 * would otherwise be shown to the editor exactly like that.
	 *
	 * @param string $title Stored title.
	 */
	private static function plain_title( string $title ): string {
		$title = wp_specialchars_decode( wp_strip_all_tags( $title ), ENT_QUOTES );

		return '' !== trim( $title ) ? $title : __( '(no title)', 'wp-custom-meta-box' );
	}

	/**
	 * Post types worth searching.
	 *
	 * The plugin's own field groups are excluded: a location rule pointing at
	 * a field group is never meaningful.
	 *
	 * @return array<int, string>
	 */
	private static function searchable_post_types(): array {
		$types = get_post_types( array( 'show_ui' => true ), 'names' );

		unset( $types[ \WPCMB\PostTypes\FieldGroupPostType::POST_TYPE ] );

		return array_values( $types );
	}

	/**
	 * Search terms.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $search   Search term.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function search_terms( string $taxonomy, string $search ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => self::SEARCH_LIMIT,
				'search'     => $search,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$results = array();

		foreach ( $terms as $term ) {
			$results[] = array(
				'value' => $term->slug,
				'label' => self::plain_title( $term->name ),
			);
		}

		return $results;
	}

	/**
	 * A single object's label, for showing a rule that is already set.
	 *
	 * Without this, editing a group whose rule points at post 4,312 would
	 * show a dropdown that does not contain it, and saving would quietly
	 * change the rule to whatever happened to be first.
	 *
	 * @param string $param Rule parameter.
	 * @param string $value Stored value.
	 */
	public static function label_for( string $param, string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$params = self::object_params();
		$kind   = $params[ $param ]['kind'] ?? '';

		if ( 'term' === $kind ) {
			$term = get_term_by( 'slug', $value, (string) $params[ $param ]['taxonomy'] );

			return $term instanceof \WP_Term ? $term->name : $value;
		}

		$post = get_post( (int) $value );

		if ( ! $post instanceof \WP_Post ) {
			return $value;
		}

		return sprintf(
			/* translators: 1: post title, 2: post id. */
			__( '%1$s (#%2$d)', 'wp-custom-meta-box' ),
			self::plain_title( $post->post_title ),
			$post->ID
		);
	}

	/**
	 * Whether a context satisfies a group's location rules.
	 *
	 * The stored shape is an OR list of AND groups: the group shows when
	 * every rule in at least one rule group matches. A group with no rules at
	 * all never matches, so an unconfigured field group stays out of the way
	 * rather than appearing on every screen on the site.
	 *
	 * @param array<int, array<int, array{param: string, operator: string, value: string}>> $location Location rules.
	 * @param Context                                                                       $context  Context to test.
	 */
	public static function match( array $location, Context $context ): bool {
		foreach ( $location as $rules ) {
			if ( ! is_array( $rules ) || array() === $rules ) {
				continue;
			}

			foreach ( $rules as $rule ) {
				if ( ! self::match_rule( $rule, $context ) ) {
					continue 2;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Whether one rule matches.
	 *
	 * A context value may be a list — the taxonomies a post has terms in, the
	 * roles a user holds — in which case `==` means "is one of" and `!=`
	 * means "is none of". A null context value means the parameter does not
	 * apply here, which never matches in either direction: a "post type is
	 * not page" rule must not start matching users and terms.
	 *
	 * @param mixed   $rule    Single rule.
	 * @param Context $context Context to test.
	 */
	private static function match_rule( $rule, Context $context ): bool {
		if ( ! is_array( $rule ) || empty( $rule['param'] ) ) {
			return false;
		}

		$actual   = $context->value( (string) $rule['param'] );
		$expected = (string) ( $rule['value'] ?? '' );
		$negated  = '!=' === ( $rule['operator'] ?? '==' );

		if ( null === $actual ) {
			$matched = false;
		} elseif ( is_array( $actual ) ) {
			$matched = in_array( $expected, $actual, true );
		} else {
			$matched = $actual === $expected;
		}

		$result = $negated ? ! $matched : $matched;

		if ( null === $actual ) {
			$result = false;
		}

		/**
		 * Filters the outcome of a single location rule.
		 *
		 * @since 1.0.0
		 *
		 * @param bool                                                    $result  Whether the rule matched.
		 * @param array{param: string, operator: string, value: string}   $rule    The rule.
		 * @param Context                                                 $context Context tested.
		 */
		return (bool) apply_filters( 'wpcmb/location/match_rule', $result, $rule, $context );
	}

	/**
	 * Render location rules as a human readable summary.
	 *
	 * @param array<int, array<int, array{param: string, operator: string, value: string}>> $location Location rules.
	 */
	public static function describe( array $location ): string {
		if ( array() === $location ) {
			return '';
		}

		$params  = self::params();
		$choices = self::choices();
		$groups  = array();

		foreach ( $location as $rules ) {
			$parts = array();

			foreach ( $rules as $rule ) {
				$label = $params[ $rule['param'] ]['label'] ?? $rule['param'];
				$value = $choices[ $rule['param'] ][ $rule['value'] ] ?? $rule['value'];

				$parts[] = sprintf(
					'%s %s %s',
					$label,
					'!=' === $rule['operator'] ? __( 'is not', 'wp-custom-meta-box' ) : __( 'is', 'wp-custom-meta-box' ),
					$value
				);
			}

			if ( array() !== $parts ) {
				$groups[] = implode( __( ' and ', 'wp-custom-meta-box' ), $parts );
			}
		}

		return implode( __( ' or ', 'wp-custom-meta-box' ), $groups );
	}

	/**
	 * Public post types, keyed by name.
	 *
	 * @return array<string, string>
	 */
	private static function post_types(): array {
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		unset( $types[ \WPCMB\PostTypes\FieldGroupPostType::POST_TYPE ] );

		return self::labelled( $types, 'label' );
	}

	/**
	 * Public taxonomies, keyed by name.
	 *
	 * @return array<string, string>
	 */
	private static function taxonomies(): array {
		return self::labelled( get_taxonomies( array( 'show_ui' => true ), 'objects' ), 'label' );
	}

	/**
	 * Editable roles, keyed by slug.
	 *
	 * @return array<string, string>
	 */
	private static function roles(): array {
		$roles = array();

		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$roles[ $slug ] = translate_user_role( $name );
		}

		return $roles;
	}

	/**
	 * Registered nav menus, keyed by term id.
	 *
	 * @return array<string, string>
	 */
	private static function nav_menus(): array {
		$menus = array();

		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[ (string) $menu->term_id ] = $menu->name;
		}

		return $menus;
	}

	/**
	 * Registered widgets, keyed by widget id base.
	 *
	 * @return array<string, string>
	 */
	private static function widgets(): array {
		global $wp_widget_factory;

		$widgets = array();

		if ( $wp_widget_factory instanceof \WP_Widget_Factory ) {
			foreach ( $wp_widget_factory->widgets as $widget ) {
				$widgets[ $widget->id_base ] = $widget->name;
			}
		}

		return $widgets;
	}

	/**
	 * Terms of a taxonomy, keyed by slug.
	 *
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return array<string, string>
	 */
	private static function terms( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 200,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$choices = array();

		foreach ( $terms as $term ) {
			$choices[ $term->slug ] = $term->name;
		}

		return $choices;
	}

	/**
	 * Theme templates available to a post type.
	 *
	 * @param string $post_type Post type name, or an empty string for all.
	 *
	 * @return array<string, string>
	 */
	private static function templates( string $post_type = '' ): array {
		$templates = array( 'default' => __( 'Default Template', 'wp-custom-meta-box' ) );

		// get_page_templates() lives in wp-admin and is absent on the front
		// end, in REST requests and in WP-CLI, all of which reach choices().
		if ( ! function_exists( 'get_page_templates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		if ( '' !== $post_type ) {
			return $templates + array_flip( get_page_templates( null, $post_type ) );
		}

		foreach ( array_keys( get_post_types( array( 'show_ui' => true ) ) ) as $type ) {
			$templates += array_flip( get_page_templates( null, $type ) );
		}

		return $templates;
	}

	/**
	 * Reduce a list of objects to a name => label map.
	 *
	 * @param array<string, object> $objects  Objects keyed by name.
	 * @param string                $property Label property.
	 *
	 * @return array<string, string>
	 */
	private static function labelled( array $objects, string $property ): array {
		$map = array();

		foreach ( $objects as $name => $object ) {
			$map[ (string) $name ] = (string) ( $object->{$property} ?? $name );
		}

		return $map;
	}
}
