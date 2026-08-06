<?php
/**
 * Relationship field types.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Fields that point at other WordPress objects.
 *
 * Post object, page link, relationship, taxonomy and user are all a select
 * over a queried list; they differ in what is queried and what is stored.
 * Options are queried once per render and capped, because a select holding
 * every post on a large site is both a slow query and an unusable control —
 * the cap is the honest failure mode, and `wpcmb/field/relationship_query`
 * is the way to narrow the list instead of raising it.
 */
final class Relationship extends FieldType {

	/**
	 * Most options to offer in one control.
	 *
	 * Deliberately a hard cap rather than a searching, paginated control.
	 * ponytail: if a site routinely exceeds this, the upgrade is an AJAX
	 * search endpoint feeding the same markup, not a bigger number.
	 */
	private const MAX_OPTIONS = 200;

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'post_object'  => __( 'Post Object', 'wp-custom-meta-box' ),
			'page_link'    => __( 'Page Link', 'wp-custom-meta-box' ),
			'relationship' => __( 'Relationship', 'wp-custom-meta-box' ),
			'taxonomy'     => __( 'Taxonomy', 'wp-custom-meta-box' ),
			'user'         => __( 'User', 'wp-custom-meta-box' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Relational', 'wp-custom-meta-box' );
	}

	/**
	 * The Dashicon shown beside a field of this type.
	 *
	 * @param string $type The specific type.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'page_link'    => 'dashicons-admin-page',
			'post_object'  => 'dashicons-admin-post',
			'relationship' => 'dashicons-admin-links',
			'taxonomy'     => 'dashicons-category',
			'user'         => 'dashicons-admin-users',
		);

		return $icons[ $type ] ?? 'dashicons-admin-links';
	}

	/**
	 * Render the control.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$choices  = $this->choices( $field );
		$multiple = $this->is_multiple( $field );
		$selected = array_map( 'strval', $this->as_list( $value ) );

		$attributes = $this->attributes(
			array(
				'name'     => $multiple ? $input_name . '[]' : $input_name,
				'id'       => $input_id,
				'class'    => 'wpcmb-input',
				'required' => ! empty( $field['required'] ),
				'multiple' => $multiple,
				'size'     => $multiple ? '8' : '',
			)
		);

		printf( '<select %s>', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.

		if ( ! $multiple ) {
			printf( '<option value="">%s</option>', esc_html__( '— Select —', 'wp-custom-meta-box' ) );
		}

		foreach ( $choices as $option => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $option ),
				in_array( (string) $option, $selected, true ) ? ' selected' : '',
				esc_html( $label )
			);
		}

		echo '</select>';

		if ( count( $choices ) >= self::MAX_OPTIONS ) {
			printf(
				'<p class="wpcmb-field__note">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: maximum number of options. */
						__( 'Showing the first %d matches. Narrow this field\'s post type or taxonomy settings to see the rest.', 'wp-custom-meta-box' ),
						self::MAX_OPTIONS
					)
				)
			);
		}
	}

	/**
	 * Keep only ids that still exist and were on offer.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function sanitize( mixed $value, array $field ): mixed {
		$allowed  = array_map( 'strval', array_keys( $this->choices( $field ) ) );
		$multiple = $this->is_multiple( $field );
		$values   = array_values( array_intersect( array_map( 'strval', $this->as_list( $value ) ), $allowed ) );

		// Page link stores a URL string; everything else stores integer ids.
		if ( 'page_link' !== ( $field['type'] ?? '' ) ) {
			$values = array_map( 'absint', $values );
		}

		return $multiple ? $values : ( $values[0] ?? '' );
	}

	/**
	 * Turn stored ids into objects, URLs or ids.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		$type   = (string) ( $field['type'] ?? '' );
		$format = (string) $this->setting( $field, 'return_format', 'id' );

		if ( 'page_link' === $type || 'id' === $format ) {
			return $value;
		}

		$mapped = array_map(
			static fn( $id ) => match ( $type ) {
				'taxonomy' => get_term( (int) $id ),
				'user'     => get_userdata( (int) $id ),
				default    => get_post( (int) $id ),
			},
			$this->as_list( $value )
		);

		$mapped = array_values( array_filter( $mapped ) );

		return $this->is_multiple( $field ) ? $mapped : ( $mapped[0] ?? null );
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		$schema = array();

		if ( in_array( $type, array( 'post_object', 'page_link', 'relationship' ), true ) ) {
			$schema['post_type'] = array(
				'label' => __( 'Post types', 'wp-custom-meta-box' ),
				'type'  => 'text',
				'help'  => __( 'Comma separated. Leave empty for all public post types.', 'wp-custom-meta-box' ),
			);
		}

		if ( 'taxonomy' === $type ) {
			$schema['taxonomy'] = array(
				'label' => __( 'Taxonomy', 'wp-custom-meta-box' ),
				'type'  => 'text',
			);
		}

		if ( 'user' === $type ) {
			$schema['role'] = array(
				'label' => __( 'Roles', 'wp-custom-meta-box' ),
				'type'  => 'text',
				'help'  => __( 'Comma separated. Leave empty for all roles.', 'wp-custom-meta-box' ),
			);
		}

		$schema['multiple'] = array(
			'label' => __( 'Allow multiple', 'wp-custom-meta-box' ),
			'type'  => 'toggle',
		);

		if ( 'page_link' !== $type ) {
			$schema['return_format'] = array(
				'label'   => __( 'Return format', 'wp-custom-meta-box' ),
				'type'    => 'select',
				'choices' => array(
					'id'     => __( 'ID', 'wp-custom-meta-box' ),
					'object' => __( 'Object', 'wp-custom-meta-box' ),
				),
			);
		}

		return $schema;
	}

	/**
	 * Whether the field accepts more than one value.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	private function is_multiple( array $field ): bool {
		if ( 'relationship' === ( $field['type'] ?? '' ) ) {
			return true;
		}

		return ! empty( $this->setting( $field, 'multiple', '' ) );
	}

	/**
	 * The selectable objects for a field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function choices( array $field ): array {
		$choices = match ( (string) ( $field['type'] ?? '' ) ) {
			'taxonomy' => $this->terms( $field ),
			'user'     => $this->users( $field ),
			'page_link' => $this->page_links( $field ),
			default    => $this->posts( $field ),
		};

		/**
		 * Filters the objects a relationship field offers.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $choices Options keyed by id or URL.
		 * @param array<string, mixed>  $field   Field definition.
		 */
		return (array) apply_filters( 'wpcmb/field/relationship_query', $choices, $field );
	}

	/**
	 * Posts, keyed by id.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function posts( array $field ): array {
		$choices = array();

		foreach ( $this->query_posts( $field ) as $post ) {
			$choices[ (string) $post->ID ] = $this->post_label( $post );
		}

		return $choices;
	}

	/**
	 * Posts keyed by permalink, for the page link type.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function page_links( array $field ): array {
		$choices = array();

		foreach ( $this->query_posts( $field ) as $post ) {
			$choices[ (string) get_permalink( $post ) ] = $this->post_label( $post );
		}

		return $choices;
	}

	/**
	 * Run the post query behind both post-backed types.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function query_posts( array $field ): array {
		$post_types = $this->list_setting( $field, 'post_type' );

		return get_posts(
			array(
				'post_type'              => array() !== $post_types ? $post_types : 'any',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => self::MAX_OPTIONS,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * A post's label, disambiguated by type when several are offered.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function post_label( \WP_Post $post ): string {
		$title = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'wp-custom-meta-box' );

		return sprintf( '%s — %s', $title, $post->post_type );
	}

	/**
	 * Terms, keyed by term id.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function terms( array $field ): array {
		$taxonomies = $this->list_setting( $field, 'taxonomy' );

		$terms = get_terms(
			array(
				'taxonomy'   => array() !== $taxonomies ? $taxonomies : get_taxonomies( array( 'show_ui' => true ) ),
				'hide_empty' => false,
				'number'     => self::MAX_OPTIONS,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$choices = array();

		foreach ( $terms as $term ) {
			$choices[ (string) $term->term_id ] = $term->name;
		}

		return $choices;
	}

	/**
	 * Users, keyed by user id.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function users( array $field ): array {
		$roles = $this->list_setting( $field, 'role' );

		$users = get_users(
			array(
				'number'   => self::MAX_OPTIONS,
				'orderby'  => 'display_name',
				'role__in' => array() !== $roles ? $roles : array(),
				'fields'   => array( 'ID', 'display_name' ),
			)
		);

		$choices = array();

		foreach ( $users as $user ) {
			$choices[ (string) $user->ID ] = $user->display_name;
		}

		return $choices;
	}

	/**
	 * Read a comma-separated setting as a list.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param string               $name  Setting name.
	 *
	 * @return array<int, string>
	 */
	private function list_setting( array $field, string $name ): array {
		$raw = $this->setting( $field, $name, '' );

		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', $raw ) ) );
		}

		return array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $raw ) ) ) );
	}

	/**
	 * Normalise a value to a list.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array<int, mixed>
	 */
	private function as_list( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( $value, 'is_scalar' ) );
		}

		return null === $value || '' === $value ? array() : array( $value );
	}
}
