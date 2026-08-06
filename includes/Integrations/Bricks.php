<?php
/**
 * Bricks Builder integration.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Integrations;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Permissions;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes fields to Bricks as `{wpcmb_fieldname}` dynamic data.
 *
 * Bricks can already read raw post meta, because values are stored in native
 * meta — that decision pays off here. What it cannot do is apply a field
 * type's formatting, so an image field gives it an attachment id and a link
 * field gives it a serialised array. These tags return the formatted value
 * instead, which is what somebody dragging a field into a template wants.
 *
 * Bricks' dynamic data API is plain filters, so no class of theirs is
 * extended and nothing breaks when they change an internal signature.
 */
final class Bricks extends Module {

	/**
	 * Tag prefix.
	 */
	private const PREFIX = 'wpcmb_';

	/**
	 * Only load when Bricks is the active theme or plugin.
	 */
	public function is_enabled(): bool {
		return defined( 'BRICKS_VERSION' );
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'bricks/dynamic_tags_list', array( $this, 'tag_list' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( $this, 'render_tag' ), 10, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( $this, 'render_content' ), 10, 3 );
		add_filter( 'bricks/frontend/render_data', array( $this, 'render_content' ), 10, 2 );
	}

	/**
	 * Offer every field in the builder's dynamic data picker.
	 *
	 * @param array<int, array<string, string>> $tags Existing tags.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function tag_list( $tags ): array {
		$tags = is_array( $tags ) ? $tags : array();

		foreach ( $this->container->get( Repository::class )->all() as $group ) {
			foreach ( $group->fields as $field ) {
				if ( ! is_array( $field ) || empty( $field['name'] ) ) {
					continue;
				}

				$tags[] = array(
					'name'  => '{' . self::PREFIX . $field['name'] . '}',
					'label' => (string) ( $field['label'] ?? $field['name'] ),
					'group' => '' !== $group->title ? $group->title : __( 'Custom Meta Box', 'wp-custom-meta-box' ),
				);
			}
		}

		return $tags;
	}

	/**
	 * Resolve a single tag.
	 *
	 * @param mixed  $tag  The tag, or an already-resolved value.
	 * @param mixed  $post The post being rendered.
	 * @param string $context Render context.
	 *
	 * @return mixed
	 */
	public function render_tag( $tag, $post = null, $context = 'text' ) {
		if ( ! is_string( $tag ) ) {
			return $tag;
		}

		$name = $this->field_name( $tag );

		return null === $name ? $tag : $this->value( $name, $post );
	}

	/**
	 * Resolve every tag inside a piece of content.
	 *
	 * @param mixed  $content The content.
	 * @param mixed  $post    The post being rendered.
	 * @param string $context Render context.
	 *
	 * @return mixed
	 */
	public function render_content( $content, $post = null, $context = 'text' ) {
		if ( ! is_string( $content ) || ! str_contains( $content, '{' . self::PREFIX ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			'/\{' . self::PREFIX . '([a-z0-9_]+)\}/',
			fn( array $matches ): string => $this->value( $matches[1], $post ),
			$content
		);
	}

	/**
	 * The field name a tag refers to, or null when it is not ours.
	 *
	 * @param string $tag Dynamic data tag.
	 */
	private function field_name( string $tag ): ?string {
		if ( 1 === preg_match( '/^\{?' . self::PREFIX . '([a-z0-9_]+)\}?$/', $tag, $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * A field's value as a string, for a builder to place in a template.
	 *
	 * @param string $name Field name.
	 * @param mixed  $post The post being rendered.
	 */
	private function value( string $name, mixed $post ): string {
		$id  = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : null;
		$ref = ObjectRef::from( $id );

		if ( ! Permissions::can_read( $ref ) ) {
			return '';
		}

		$value = $this->container->get( Values::class )->get( $name, $ref );

		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		if ( is_array( $value ) ) {
			// A link resolves to its URL, because that is what a builder will
			// almost always be binding it to. Anything else structured has no
			// single sensible string form and resolves to nothing rather than
			// printing a serialised array into a page.
			return isset( $value['url'] ) && is_scalar( $value['url'] ) ? (string) $value['url'] : '';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
