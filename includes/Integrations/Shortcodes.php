<?php
/**
 * Field value shortcodes.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Integrations;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Permissions;
use WPCMB\Fields\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs a field value from a shortcode.
 *
 * Anyone who can write a shortcode can already write arbitrary content, but a
 * shortcode is also reachable from a comment or a submitted post on some
 * sites, so this checks that the current visitor may read the object it is
 * asked about rather than assuming the author of the content could.
 */
final class Shortcodes extends Module {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'wpcmb_field';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * Attributes:
	 *
	 * - `name`      Field name. Required.
	 * - `object`    Object identifier, defaults to the current post.
	 * - `format`    `1` to apply field-type formatting, `0` for the raw value.
	 * - `separator` Joins a multi-value field.
	 * - `fallback`  Shown when the value is empty.
	 * - `key`       Reads one key out of a structured value, e.g. `url`.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'name'      => '',
				'object'    => '',
				'format'    => '1',
				'separator' => ', ',
				'fallback'  => '',
				'key'       => '',
			),
			is_array( $atts ) ? array_change_key_case( $atts ) : array(),
			self::TAG
		);

		$name = sanitize_key( $atts['name'] );

		if ( '' === $name ) {
			return '';
		}

		$ref = ObjectRef::from( '' !== $atts['object'] ? $atts['object'] : null );

		if ( ! Permissions::can_read( $ref ) ) {
			return esc_html( $atts['fallback'] );
		}

		$value = $this->container->get( Values::class )->get( $name, $ref, '0' !== $atts['format'] );

		if ( '' !== $atts['key'] && is_array( $value ) ) {
			$value = $value[ sanitize_key( $atts['key'] ) ] ?? '';
		}

		return esc_html( $this->stringify( $value, $atts['separator'], $atts['fallback'] ) );
	}

	/**
	 * Reduce a value to something printable.
	 *
	 * Structured values print their fallback rather than "Array": a repeater
	 * has no single sensible one-line form, and printing JSON into a page
	 * would look like a bug to whoever wrote the shortcode.
	 *
	 * @param mixed  $value     Field value.
	 * @param string $separator Separator for lists.
	 * @param string $fallback  Text for an empty value.
	 */
	private function stringify( mixed $value, string $separator, string $fallback ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'wp-custom-meta-box' ) : $fallback;
		}

		if ( is_array( $value ) ) {
			$flat = array_filter( $value, 'is_scalar' );

			return array() === $flat ? $fallback : implode( $separator, $flat );
		}

		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return $fallback;
		}

		return (string) $value;
	}
}
