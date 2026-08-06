<?php
/**
 * Field group model.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * A field group: title, fields, location rules and display settings.
 *
 * Immutable value object built from the JSON stored in the group's
 * `_wpcmb_config` meta. sanitize() is the trust boundary for anything that
 * arrives from a request; nothing else in the plugin re-validates the shape.
 */
final class FieldGroup {

	/**
	 * Post id backing this group, 0 for an unsaved group.
	 *
	 * @var int
	 */
	public readonly int $id;

	/**
	 * Stable group key, e.g. `group_6a1f...`.
	 *
	 * @var string
	 */
	public readonly string $key;

	/**
	 * Human readable title.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * Field definitions.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public readonly array $fields;

	/**
	 * Location rules: an OR list of AND groups.
	 *
	 * @var array<int, array<int, array{param: string, operator: string, value: string}>>
	 */
	public readonly array $location;

	/**
	 * Display settings.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $settings;

	/**
	 * Constructor.
	 *
	 * @param int                              $id       Post id.
	 * @param string                           $key      Group key.
	 * @param string                           $title    Title.
	 * @param array<int, array<string, mixed>> $fields   Field definitions.
	 * @param array<int, array<int, array>>    $location Location rules.
	 * @param array<string, mixed>             $settings Display settings.
	 */
	public function __construct(
		int $id = 0,
		string $key = '',
		string $title = '',
		array $fields = array(),
		array $location = array(),
		array $settings = array()
	) {
		$this->id       = $id;
		$this->key      = '' !== $key ? $key : self::generate_key( 'group' );
		$this->title    = $title;
		$this->fields   = $fields;
		$this->location = $location;
		$this->settings = $settings + self::default_settings();
	}

	/**
	 * Default display settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'active'             => true,
			'position'           => 'normal',
			'style'              => 'default',
			'label_placement'    => 'top',
			'menu_order'         => 0,
			'description'        => '',
			'hide_on_screen'     => array(),
			'block_enabled'      => false,
			'block_name'         => '',
			'block_icon'         => '',
			'block_category'     => '',
			'block_description'  => '',
			'block_keywords'     => '',
			'block_mode'         => 'auto',
			'block_supports'     => array(),
			'block_inner_blocks' => false,
		);
	}

	/**
	 * Build a group from a post and its stored configuration.
	 *
	 * @param \WP_Post $post Field group post.
	 */
	public static function from_post( \WP_Post $post ): self {
		$raw    = get_post_meta( $post->ID, \WPCMB\PostTypes\FieldGroupPostType::META_CONFIG, true );
		$config = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		$config = is_array( $config ) ? $config : array();

		$config['title'] = $post->post_title;
		$config['id']    = $post->ID;

		// Active state is the post status, so the list table's publish/draft
		// controls and the editor's toggle can never disagree.
		$settings           = isset( $config['settings'] ) && is_array( $config['settings'] ) ? $config['settings'] : array();
		$settings['active'] = 'publish' === $post->post_status;
		$config['settings'] = $settings;

		return self::from_array( $config );
	}

	/**
	 * Build a group from an already-sanitized array.
	 *
	 * @param array<string, mixed> $config Group configuration.
	 */
	public static function from_array( array $config ): self {
		return new self(
			(int) ( $config['id'] ?? 0 ),
			(string) ( $config['key'] ?? '' ),
			(string) ( $config['title'] ?? '' ),
			isset( $config['fields'] ) && is_array( $config['fields'] ) ? $config['fields'] : array(),
			isset( $config['location'] ) && is_array( $config['location'] ) ? $config['location'] : array(),
			isset( $config['settings'] ) && is_array( $config['settings'] ) ? $config['settings'] : array()
		);
	}

	/**
	 * Export as a plain array, suitable for json_encode().
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'key'      => $this->key,
			'title'    => $this->title,
			'fields'   => $this->fields,
			'location' => $this->location,
			'settings' => $this->settings,
		);
	}

	/**
	 * Whether the group is active.
	 */
	public function is_active(): bool {
		return (bool) ( $this->settings['active'] ?? true );
	}

	/**
	 * Return a copy with different fields.
	 *
	 * @param array<int, array<string, mixed>> $fields Field definitions.
	 */
	public function with_fields( array $fields ): self {
		return new self( $this->id, $this->key, $this->title, $fields, $this->location, $this->settings );
	}

	/**
	 * Generate a collision-resistant key.
	 *
	 * @param string $prefix Key prefix, e.g. `group` or `field`.
	 */
	public static function generate_key( string $prefix ): string {
		return $prefix . '_' . substr( md5( uniqid( $prefix, true ) ), 0, 13 );
	}

	/**
	 * Sanitize untrusted group configuration.
	 *
	 * Everything that reaches storage passes through here: unknown keys are
	 * dropped rather than escaped, so the stored shape is always known.
	 *
	 * @param array<string, mixed> $raw Untrusted configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $raw ): array {
		return array(
			'key'      => self::sanitize_key_string( (string) ( $raw['key'] ?? '' ), 'group' ),
			'title'    => sanitize_text_field( (string) ( $raw['title'] ?? '' ) ),
			'fields'   => self::sanitize_fields( $raw['fields'] ?? array() ),
			'location' => self::sanitize_location( $raw['location'] ?? array() ),
			'settings' => self::sanitize_settings( $raw['settings'] ?? array() ),
		);
	}

	/**
	 * Sanitize a list of field definitions, recursing into sub fields.
	 *
	 * @param mixed $raw   Untrusted field list.
	 * @param int   $depth Current nesting depth.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_fields( $raw, int $depth = 0 ): array {
		if ( ! is_array( $raw ) || $depth > 10 ) {
			return array();
		}

		$fields = array();

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = self::sanitize_field_name( (string) ( $field['name'] ?? '' ) );
			$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );

			if ( '' === $name ) {
				continue;
			}

			$clean = array(
				'key'          => self::sanitize_key_string( (string) ( $field['key'] ?? '' ), 'field' ),
				'name'         => $name,
				'label'        => sanitize_text_field( (string) ( $field['label'] ?? '' ) ),
				'type'         => '' !== $type ? $type : 'text',
				'instructions' => wp_kses_post( (string) ( $field['instructions'] ?? '' ) ),
				'required'     => ! empty( $field['required'] ),
				'default'      => self::sanitize_scalar_or_list( $field['default'] ?? '' ),
				'placeholder'  => sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) ),
				'wrapper'      => array(
					'width' => self::sanitize_width( $field['wrapper']['width'] ?? '' ),
					'class' => sanitize_html_class( (string) ( $field['wrapper']['class'] ?? '' ) ),
					'id'    => sanitize_html_class( (string) ( $field['wrapper']['id'] ?? '' ) ),
				),
				'settings'     => self::sanitize_type_settings( $field['settings'] ?? array() ),
				'conditional'  => self::sanitize_conditional( $field['conditional'] ?? array() ),
			);

			if ( isset( $field['sub_fields'] ) ) {
				$clean['sub_fields'] = self::sanitize_fields( $field['sub_fields'], $depth + 1 );
			}

			if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				$clean['layouts'] = array();
				foreach ( $field['layouts'] as $layout ) {
					if ( ! is_array( $layout ) ) {
						continue;
					}
					// A layout is named explicitly, or named after its label.
					$layout_name = self::sanitize_field_name( (string) ( $layout['name'] ?? '' ) );

					if ( '' === $layout_name ) {
						$layout_name = self::sanitize_field_name( (string) ( $layout['label'] ?? '' ) );
					}

					// A layout with no usable name is dropped: rows record
					// their layout by name, and an unnamed layout could never
					// be matched back to its rows.
					if ( '' === $layout_name ) {
						continue;
					}

					$clean['layouts'][] = array(
						'key'        => self::sanitize_key_string( (string) ( $layout['key'] ?? '' ), 'layout' ),
						'name'       => $layout_name,
						'label'      => sanitize_text_field( (string) ( $layout['label'] ?? '' ) ),
						'settings'   => self::sanitize_type_settings( $layout['settings'] ?? array() ),
						'sub_fields' => self::sanitize_fields( $layout['sub_fields'] ?? array(), $depth + 1 ),
					);
				}
			}

			$fields[] = $clean;
		}

		return $fields;
	}

	/**
	 * Sanitize per-type settings.
	 *
	 * Field types own their settings, so values are sanitized generically
	 * rather than against a per-type schema: scalars are text, lists recurse
	 * one level. Field types re-cast what they read.
	 *
	 * @param mixed $raw Untrusted settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function sanitize_type_settings( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( $raw as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$clean[ $key ] = self::sanitize_scalar_or_list( $value );
		}

		return $clean;
	}

	/**
	 * Sanitize a scalar, or a one-level list/map of scalars.
	 *
	 * @param mixed $value Untrusted value.
	 *
	 * @return string|bool|int|float|array<array-key, string|bool|int|float>
	 */
	private static function sanitize_scalar_or_list( $value ) {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				if ( is_array( $item ) ) {
					continue;
				}
				$clean[ is_int( $key ) ? $key : sanitize_text_field( (string) $key ) ] =
					is_scalar( $item ) && ! is_string( $item ) ? $item : sanitize_textarea_field( (string) $item );
			}

			return $clean;
		}

		return sanitize_textarea_field( (string) $value );
	}

	/**
	 * Sanitize conditional logic rules.
	 *
	 * @param mixed $raw Untrusted conditional config.
	 *
	 * @return array<string, mixed>
	 */
	private static function sanitize_conditional( $raw ): array {
		if ( ! is_array( $raw ) || empty( $raw['rules'] ) || ! is_array( $raw['rules'] ) ) {
			return array();
		}

		$rules = array();

		foreach ( $raw['rules'] as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
				continue;
			}
			$rules[] = array(
				'field'    => self::sanitize_key_string( (string) $rule['field'], 'field' ),
				'operator' => self::sanitize_operator( (string) ( $rule['operator'] ?? '==' ) ),
				'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
			);
		}

		if ( array() === $rules ) {
			return array();
		}

		return array(
			'action' => in_array( $raw['action'] ?? 'show', array( 'show', 'hide' ), true ) ? $raw['action'] : 'show',
			'logic'  => in_array( $raw['logic'] ?? 'all', array( 'all', 'any' ), true ) ? $raw['logic'] : 'all',
			'rules'  => $rules,
		);
	}

	/**
	 * Sanitize location rules.
	 *
	 * @param mixed $raw Untrusted location config.
	 *
	 * @return array<int, array<int, array{param: string, operator: string, value: string}>>
	 */
	private static function sanitize_location( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$params = array_keys( Locations::params() );
		$groups = array();

		foreach ( $raw as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$rules = array();

			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$param = sanitize_key( (string) ( $rule['param'] ?? '' ) );
				if ( ! in_array( $param, $params, true ) ) {
					continue;
				}
				$rules[] = array(
					'param'    => $param,
					'operator' => in_array( $rule['operator'] ?? '==', array( '==', '!=' ), true ) ? $rule['operator'] : '==',
					'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
				);
			}

			if ( array() !== $rules ) {
				$groups[] = $rules;
			}
		}

		return $groups;
	}

	/**
	 * Sanitize display settings.
	 *
	 * @param mixed $raw Untrusted settings.
	 *
	 * @return array<string, mixed>
	 */
	private static function sanitize_settings( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();

		$hide = array();
		if ( isset( $raw['hide_on_screen'] ) && is_array( $raw['hide_on_screen'] ) ) {
			$hide = array_values( array_filter( array_map( 'sanitize_key', $raw['hide_on_screen'] ) ) );
		}

		$supports = array();

		if ( isset( $raw['block_supports'] ) && is_array( $raw['block_supports'] ) ) {
			$supports = array_values(
				array_intersect(
					array_map( 'sanitize_key', $raw['block_supports'] ),
					array( 'align', 'anchor', 'custom_class_name', 'color', 'spacing', 'typography' )
				)
			);
		}

		return array(
			// Absent means active. Stored groups have this overwritten from
			// their post status; groups registered in code do not, and one
			// that defaulted to inactive would silently never appear.
			'active'             => (bool) ( $raw['active'] ?? true ),

			// Block settings. A group is a block only when it says so and
			// names itself: a block name is part of a site's saved content,
			// so it must be chosen deliberately rather than derived from a
			// title that someone may later rename.
			'block_enabled'      => ! empty( $raw['block_enabled'] ),
			'block_name'         => self::sanitize_field_name( (string) ( $raw['block_name'] ?? '' ) ),
			'block_icon'         => sanitize_text_field( (string) ( $raw['block_icon'] ?? '' ) ),
			// Same normalisation as a field name: "Layout Things" becomes
			// layout_things rather than layoutthings, which is what a person
			// typing a category with a space in it means.
			'block_category'     => self::sanitize_field_name( (string) ( $raw['block_category'] ?? '' ) ),
			'block_description'  => sanitize_text_field( (string) ( $raw['block_description'] ?? '' ) ),
			'block_keywords'     => sanitize_text_field( (string) ( $raw['block_keywords'] ?? '' ) ),
			'block_mode'         => self::one_of( $raw['block_mode'] ?? null, array( 'auto', 'preview', 'edit' ) ),
			'block_supports'     => $supports,
			'block_inner_blocks' => ! empty( $raw['block_inner_blocks'] ),
			'position'           => self::one_of( $raw['position'] ?? null, array( 'normal', 'side', 'advanced' ) ),
			'style'              => self::one_of( $raw['style'] ?? null, array( 'default', 'seamless' ) ),
			'label_placement'    => self::one_of( $raw['label_placement'] ?? null, array( 'top', 'left' ) ),
			'menu_order'         => (int) ( $raw['menu_order'] ?? 0 ),
			'description'        => sanitize_text_field( (string) ( $raw['description'] ?? '' ) ),
			'hide_on_screen'     => $hide,
		);
	}

	/**
	 * Return the value if it is one of the allowed ones, else the first allowed.
	 *
	 * @param mixed             $value   Untrusted value.
	 * @param array<int,string> $allowed Allowed values, most-default first.
	 */
	private static function one_of( $value, array $allowed ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $allowed[0];
	}

	/**
	 * Reduce a label or typed name to a meta key.
	 *
	 * Whitespace and dashes become underscores before sanitize_key() drops
	 * them, so "First Name" becomes `first_name` rather than `firstname`.
	 * The builder's JavaScript applies the same rule; if the two disagree, a
	 * field is silently renamed on save and its stored values are orphaned.
	 *
	 * @param string $name Untrusted name.
	 */
	public static function sanitize_field_name( string $name ): string {
		return sanitize_key( (string) preg_replace( '/[\s\-]+/', '_', trim( $name ) ) );
	}

	/**
	 * Sanitize a group/field/layout key, generating one when absent or malformed.
	 *
	 * @param string $key    Untrusted key.
	 * @param string $prefix Prefix to generate with.
	 */
	private static function sanitize_key_string( string $key, string $prefix ): string {
		$key = sanitize_key( $key );

		return preg_match( '/^(group|field|layout)_[a-z0-9]+$/', $key ) ? $key : self::generate_key( $prefix );
	}

	/**
	 * Sanitize a conditional operator.
	 *
	 * @param string $operator Untrusted operator.
	 */
	private static function sanitize_operator( string $operator ): string {
		$allowed = array( '==', '!=', '>', '<', 'contains', 'not_contains', 'empty', 'not_empty', 'pattern' );

		return in_array( $operator, $allowed, true ) ? $operator : '==';
	}

	/**
	 * Sanitize a wrapper width percentage.
	 *
	 * @param mixed $width Untrusted width.
	 */
	private static function sanitize_width( $width ): string {
		$width = (int) $width;

		return $width > 0 && $width <= 100 ? (string) $width : '';
	}
}
