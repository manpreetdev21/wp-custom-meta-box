<?php
/**
 * Field group repository.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes field groups.
 *
 * Reading every group costs one query per request, memoised for the request
 * and cached in the object cache, because every admin screen and every
 * front-end field read needs the full set to evaluate location rules against.
 */
final class Repository {

	/**
	 * Object cache group.
	 */
	private const CACHE_GROUP = 'wpcmb';

	/**
	 * Object cache key for the full group list.
	 */
	private const CACHE_KEY = 'field_groups';

	/**
	 * Request-level memoisation.
	 *
	 * @var array<string, FieldGroup>|null
	 */
	private ?array $groups = null;

	/**
	 * Request-level name to field index.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $index = null;

	/**
	 * Groups registered in code rather than stored in the database.
	 *
	 * @var array<string, FieldGroup>
	 */
	private array $registered = array();

	/**
	 * Whether the registration hook has already run.
	 *
	 * @var bool
	 */
	private bool $collecting = false;

	/**
	 * Bumped on every flush, so caches keyed by it expire without this class
	 * having to know who holds them.
	 *
	 * @var int
	 */
	private int $version = 0;

	/**
	 * A counter that changes whenever the group set may have changed.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Register a field group from code.
	 *
	 * Registered groups behave like stored ones but are never written to the
	 * database, so a theme or plugin can ship field groups in version control.
	 * A registered group loses to a stored one with the same key, which is
	 * what makes the "export to PHP, then keep editing in the admin" path work.
	 *
	 * @param array<string, mixed> $config Group configuration, sanitized here.
	 */
	public function register( array $config ): FieldGroup {
		$group = FieldGroup::from_array( FieldGroup::sanitize( $config ) );

		$this->registered[ $group->key ] = $group;

		// Drop the merged view so a group registered after the first read is
		// still picked up. Registering from inside the collection hook is
		// safe: all() reassigns the merged view once collection returns.
		$this->groups = null;
		$this->index  = null;
		++$this->version;

		return $group;
	}

	/**
	 * Every field group, keyed by group key, ordered by menu order then title.
	 *
	 * @param bool $include_inactive Whether to include deactivated groups.
	 *
	 * @return array<string, FieldGroup>
	 */
	public function all( bool $include_inactive = false ): array {
		if ( null === $this->groups ) {
			// Union order matters: keys already present in the stored set are
			// kept, so a stored group overrides a registered one.
			$this->groups = $this->load() + $this->collect();
		}

		if ( $include_inactive ) {
			return $this->groups;
		}

		return array_filter( $this->groups, static fn( FieldGroup $group ): bool => $group->is_active() );
	}

	/**
	 * A single group by key or post id.
	 *
	 * @param string|int $identifier Group key or post id.
	 */
	public function get( string|int $identifier ): ?FieldGroup {
		$groups = $this->all( true );

		if ( is_string( $identifier ) && isset( $groups[ $identifier ] ) ) {
			return $groups[ $identifier ];
		}

		$id = (int) $identifier;

		if ( $id > 0 ) {
			foreach ( $groups as $group ) {
				if ( $group->id === $id ) {
					return $group;
				}
			}

			$post = get_post( $id );

			if ( $post instanceof \WP_Post && FieldGroupPostType::POST_TYPE === $post->post_type ) {
				return FieldGroup::from_post( $post );
			}
		}

		return null;
	}

	/**
	 * Find a top-level field by its name, across every group.
	 *
	 * Values are stored under the field name, so a name identifies a field on
	 * its own — no companion meta row recording which field key a value came
	 * from, and no dependence on the group's location still matching.
	 *
	 * ponytail: two groups using the same field name resolve to whichever is
	 * indexed first. They would already collide in storage, so the ambiguity
	 * is in the configuration rather than here; surface it in the editor if
	 * it turns out to bite.
	 *
	 * @param string $name Field name.
	 *
	 * @return array<string, mixed>|null
	 */
	public function field_by_name( string $name ): ?array {
		return $this->index()[ $name ] ?? null;
	}

	/**
	 * Find any field by its key, at any depth.
	 *
	 * Keys are generated to be unique across the site, so a key identifies a
	 * field on its own — no path from the group root is needed, and none has
	 * to be trusted from a request.
	 *
	 * @param string $key Field key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function field_by_key( string $key ): ?array {
		foreach ( $this->all( true ) as $group ) {
			$found = $this->search( $group->fields, $key );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Depth-first search of a field tree for a key.
	 *
	 * @param array<int, mixed> $fields Field definitions.
	 * @param string            $key    Field key.
	 * @param int               $depth  Current depth.
	 *
	 * @return array<string, mixed>|null
	 */
	private function search( array $fields, string $key, int $depth = 0 ): ?array {
		if ( $depth > 10 ) {
			return null;
		}

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( ( $field['key'] ?? '' ) === $key ) {
				return $field;
			}

			$children = is_array( $field['sub_fields'] ?? null ) ? $field['sub_fields'] : array();

			foreach ( is_array( $field['layouts'] ?? null ) ? $field['layouts'] : array() as $layout ) {
				if ( is_array( $layout ) && is_array( $layout['sub_fields'] ?? null ) ) {
					$children = array_merge( $children, $layout['sub_fields'] );
				}
			}

			$found = $this->search( $children, $key, $depth + 1 );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Every top-level field name in use, across every group.
	 *
	 * @return array<int, string>
	 */
	public function field_names(): array {
		return array_keys( $this->index() );
	}

	/**
	 * Build the name to field index over all groups, including inactive ones.
	 *
	 * Inactive groups are included so their stored values stay readable and
	 * are still cleaned up on uninstall.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function index(): array {
		if ( null !== $this->index ) {
			return $this->index;
		}

		$this->index = array();

		foreach ( $this->all( true ) as $group ) {
			foreach ( $group->fields as $field ) {
				$name = (string) ( $field['name'] ?? '' );

				if ( '' !== $name && ! isset( $this->index[ $name ] ) ) {
					$this->index[ $name ] = $field;
				}
			}
		}

		return $this->index;
	}

	/**
	 * Persist a group's configuration to its post.
	 *
	 * The caller owns capability and nonce checks; this only writes.
	 *
	 * @param int                  $post_id Field group post id.
	 * @param array<string, mixed> $config  Already-sanitized configuration.
	 */
	public function save( int $post_id, array $config ): void {
		unset( $config['title'] );

		update_post_meta(
			$post_id,
			FieldGroupPostType::META_CONFIG,
			wp_slash( (string) wp_json_encode( $config ) )
		);

		$this->flush();
	}

	/**
	 * Duplicate a group, giving the copy fresh keys and draft status.
	 *
	 * @param int $post_id Source field group post id.
	 *
	 * @return int|\WP_Error New post id, or an error.
	 */
	public function duplicate( int $post_id ) {
		$source = get_post( $post_id );

		if ( ! $source instanceof \WP_Post || FieldGroupPostType::POST_TYPE !== $source->post_type ) {
			return new \WP_Error( 'wpcmb_not_found', __( 'Field group not found.', 'wp-custom-meta-box' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => FieldGroupPostType::POST_TYPE,
				'post_status' => 'draft',
				/* translators: %s: field group title. */
				'post_title'  => sprintf( __( '%s (copy)', 'wp-custom-meta-box' ), $source->post_title ),
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$config = $this->rekey( FieldGroup::from_post( $source )->to_array() );

		$this->save( (int) $new_id, $config );

		return (int) $new_id;
	}

	/**
	 * Drop caches. Called on any write to a field group.
	 */
	public function flush(): void {
		$this->groups = null;
		$this->index  = null;
		++$this->version;

		// Registered groups survive a flush: they come from code that has
		// already run this request and will not run again.
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
		wp_cache_delete( 'location_choices', self::CACHE_GROUP );
	}

	/**
	 * Ask code-based registrars for their groups, once per request.
	 *
	 * Fired lazily on first read rather than on `init`, so a field read that
	 * happens before `init` still sees registered groups, and one that never
	 * happens costs nothing.
	 *
	 * @return array<string, FieldGroup>
	 */
	private function collect(): array {
		if ( $this->collecting ) {
			return $this->registered;
		}

		$this->collecting = true;

		/**
		 * Fires when field groups registered in code should be added.
		 *
		 * Handlers call wpcmb_register_field_group(). This is the hook the
		 * Tools screen's PHP export writes against.
		 *
		 * @since 1.0.0
		 *
		 * @param Repository $repository Field group repository.
		 */
		do_action( 'wpcmb/register_field_groups', $this );

		return $this->registered;
	}

	/**
	 * Load every group from the database, or the object cache.
	 *
	 * @return array<string, FieldGroup>
	 */
	private function load(): array {
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return array_map( array( FieldGroup::class, 'from_array' ), $cached );
		}

		$posts = get_posts(
			array(
				'post_type'              => FieldGroupPostType::POST_TYPE,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => -1,
				'orderby'                => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$groups = array();
		$raw    = array();

		foreach ( $posts as $post ) {
			$group                 = FieldGroup::from_post( $post );
			$groups[ $group->key ] = $group;
			$raw[ $group->key ]    = array( 'id' => $group->id ) + $group->to_array();
		}

		wp_cache_set( self::CACHE_KEY, $raw, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $groups;
	}

	/**
	 * Give every key in a group configuration a fresh value.
	 *
	 * Rewriting the encoded configuration rather than walking the tree means
	 * references to a key are remapped too — conditional logic rules point at
	 * field keys, and a tree walk would rename the fields while leaving the
	 * rules pointing at the original group's fields.
	 *
	 * @param array<string, mixed> $config Group configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function rekey( array $config ): array {
		$json = (string) wp_json_encode( $config );

		if ( ! preg_match_all( '/\b(group|field|layout)_[a-z0-9]+\b/', $json, $matches ) ) {
			return $config;
		}

		$replacements = array();

		foreach ( array_unique( $matches[0] ) as $index => $old ) {
			$replacements[ $old ] = FieldGroup::generate_key( $matches[1][ $index ] );
		}

		$json    = strtr( $json, $replacements );
		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : $config;
	}
}
