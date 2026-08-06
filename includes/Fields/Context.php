<?php
/**
 * Location matching context.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the thing a field group is being matched against.
 *
 * Values are computed on demand and memoised, never up front. A typical
 * location rule set tests one or two parameters, and several of the others
 * cost a term or template query — so eagerly building a full description of
 * the screen would run those queries on every page load to answer a question
 * nobody asked.
 */
final class Context {

	/**
	 * The object being described.
	 *
	 * @var ObjectRef
	 */
	public readonly ObjectRef $ref;

	/**
	 * Screen details the object itself cannot supply, such as which user form
	 * is being rendered or which widget is being edited.
	 *
	 * @var array<string, string>
	 */
	private array $extra;

	/**
	 * Memoised parameter values.
	 *
	 * @var array<string, string|array<int, string>|null>
	 */
	private array $resolved = array();

	/**
	 * Constructor.
	 *
	 * @param ObjectRef             $ref   Object reference.
	 * @param array<string, string> $extra Screen details, e.g. `user_form`.
	 */
	public function __construct( ObjectRef $ref, array $extra = array() ) {
		$this->ref   = $ref;
		$this->extra = $extra;
	}

	/**
	 * Build a context for a loose identifier.
	 *
	 * @param mixed                 $identifier Object identifier, see ObjectRef::from().
	 * @param array<string, string> $extra      Screen details.
	 */
	public static function for( mixed $identifier = null, array $extra = array() ): self {
		return new self( ObjectRef::from( $identifier ), $extra );
	}

	/**
	 * A stable string form of the screen extras, for cache keys.
	 *
	 * Two contexts for the same object can still differ — the same user on
	 * the add form and on the edit form — so resolved groups cannot be cached
	 * by object reference alone.
	 */
	public function signature_extra(): string {
		$extra = $this->extra;
		ksort( $extra );

		return (string) wp_json_encode( $extra );
	}

	/**
	 * The value of a location rule parameter, or null when it does not apply.
	 *
	 * An array means "any of these match"; null means the parameter is
	 * meaningless here, which never matches.
	 *
	 * @param string $param Rule parameter.
	 *
	 * @return string|array<int, string>|null
	 */
	public function value( string $param ): string|array|null {
		if ( array_key_exists( $param, $this->resolved ) ) {
			return $this->resolved[ $param ];
		}

		$value = match ( $param ) {
			'post_type', 'comment'                => $this->post_value( 'post_type' ),
			'post_status'                         => $this->post_value( 'post_status' ),
			'post_format'                         => $this->post_format(),
			'post_template', 'page_template'      => $this->template(),
			'page_type'                           => $this->page_type(),
			'page_parent'                         => $this->post_value( 'post_parent' ),
			'post', 'page'                        => $this->post_id_string(),
			'post_category'                       => $this->term_slugs( 'category' ),
			'post_taxonomy'                       => $this->post_taxonomies(),
			'attachment'                          => $this->is_post_type( 'attachment' ) ? 'all' : null,
			'nav_menu_item'                       => $this->is_post_type( 'nav_menu_item' ) ? 'all' : null,
			'taxonomy'                            => $this->taxonomy(),
			'nav_menu'                            => $this->nav_menu(),
			'user_role'                           => $this->user_roles(),
			'user_form'                           => $this->extra['user_form'] ?? null,
			'options_page'                        => ObjectRef::OPTION === $this->ref->type ? (string) $this->ref->id : null,
			'widget'                              => $this->extra['widget'] ?? null,
			'block'                               => $this->extra['block'] ?? null,
			'current_user'                        => $this->current_user(),
			'current_user_role'                   => $this->current_user_roles(),
			default                               => $this->custom( $param ),
		};

		$this->resolved[ $param ] = $value;

		return $value;
	}

	/**
	 * A property of the referenced post.
	 *
	 * @param string $property Post property.
	 */
	private function post_value( string $property ): ?string {
		$post = $this->post();

		return $post instanceof \WP_Post ? (string) $post->{$property} : null;
	}

	/**
	 * The referenced post, if this context describes one.
	 */
	private function post(): ?\WP_Post {
		if ( ObjectRef::POST !== $this->ref->type ) {
			return null;
		}

		$post = get_post( (int) $this->ref->id );

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Whether the referenced object is a post of the given type.
	 *
	 * @param string $post_type Post type name.
	 */
	private function is_post_type( string $post_type ): bool {
		return $post_type === $this->post_value( 'post_type' );
	}

	/**
	 * The post id as a string, for rules that compare against a specific post.
	 */
	private function post_id_string(): ?string {
		$post = $this->post();

		return $post instanceof \WP_Post ? (string) $post->ID : null;
	}

	/**
	 * The post format, falling back to `standard`.
	 */
	private function post_format(): ?string {
		$post = $this->post();

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$format = get_post_format( $post );

		return is_string( $format ) && '' !== $format ? $format : 'standard';
	}

	/**
	 * The assigned page template, falling back to `default`.
	 */
	private function template(): ?string {
		$post = $this->post();

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$template = get_page_template_slug( $post );

		return is_string( $template ) && '' !== $template ? $template : 'default';
	}

	/**
	 * Where the referenced page sits in the page tree.
	 *
	 * Returns every applicable label, so a rule may match on any one of them.
	 *
	 * @return array<int, string>|null
	 */
	private function page_type(): ?array {
		$post = $this->post();

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$types = array();

		if ( (int) get_option( 'page_on_front' ) === $post->ID ) {
			$types[] = 'front_page';
		}

		if ( (int) get_option( 'page_for_posts' ) === $post->ID ) {
			$types[] = 'posts_page';
		}

		$types[] = $post->post_parent > 0 ? 'child' : 'top_level';

		if ( 0 !== count(
			get_children(
				array(
					'post_parent' => $post->ID,
					'post_type'   => $post->post_type,
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			)
		) ) {
			$types[] = 'parent';
		}

		return $types;
	}

	/**
	 * Term slugs the referenced post belongs to, in one taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 *
	 * @return array<int, string>|null
	 */
	private function term_slugs( string $taxonomy ): ?array {
		$post = $this->post();

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$terms = get_the_terms( $post, $taxonomy );

		return is_array( $terms ) ? wp_list_pluck( $terms, 'slug' ) : array();
	}

	/**
	 * Taxonomies the referenced post has terms in.
	 *
	 * @return array<int, string>|null
	 */
	private function post_taxonomies(): ?array {
		$post = $this->post();

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$taxonomies = array();

		foreach ( get_object_taxonomies( $post ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );

			if ( is_array( $terms ) && array() !== $terms ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}

	/**
	 * The referenced term's taxonomy.
	 */
	private function taxonomy(): ?string {
		if ( ObjectRef::TERM !== $this->ref->type ) {
			return null;
		}

		$term = get_term( (int) $this->ref->id );

		return $term instanceof \WP_Term ? $term->taxonomy : null;
	}

	/**
	 * The referenced term id, when it is a nav menu.
	 */
	private function nav_menu(): ?string {
		return 'nav_menu' === $this->taxonomy() ? (string) $this->ref->id : null;
	}

	/**
	 * Roles of the referenced user.
	 *
	 * @return array<int, string>|null
	 */
	private function user_roles(): ?array {
		if ( ObjectRef::USER !== $this->ref->type ) {
			return null;
		}

		$user = get_userdata( (int) $this->ref->id );

		return $user instanceof \WP_User ? array_values( $user->roles ) : array();
	}

	/**
	 * Applicable labels for the logged-in user's situation.
	 *
	 * @return array<int, string>
	 */
	private function current_user(): array {
		$labels = is_user_logged_in() ? array( 'logged_in' ) : array();

		$labels[] = is_admin() ? 'viewing_back' : 'viewing_front';

		return $labels;
	}

	/**
	 * Roles of the logged-in user.
	 *
	 * @return array<int, string>
	 */
	private function current_user_roles(): array {
		$user = wp_get_current_user();

		return $user instanceof \WP_User ? array_values( $user->roles ) : array();
	}

	/**
	 * Resolve a parameter registered by third-party code.
	 *
	 * @param string $param Rule parameter.
	 *
	 * @return string|array<int, string>|null
	 */
	private function custom( string $param ): string|array|null {
		/**
		 * Filters the value of a location rule parameter this class does not know.
		 *
		 * Pair with `wpcmb/location/params` to add a rule parameter and teach
		 * the matcher how to evaluate it.
		 *
		 * @since 1.0.0
		 *
		 * @param string|array<int, string>|null $value   Parameter value.
		 * @param string                         $param   Rule parameter.
		 * @param Context                        $context Context being described.
		 */
		return apply_filters( 'wpcmb/location/value', null, $param, $this );
	}
}
