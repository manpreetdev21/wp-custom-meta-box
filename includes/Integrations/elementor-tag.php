<?php
/**
 * Elementor dynamic tag.
 *
 * Loaded by hand from Integrations\Elementor, never by the autoloader: the
 * class below extends an Elementor base class, so it can only be declared
 * once Elementor has loaded. The filename deliberately does not match the
 * class name, and the file is excluded from Composer's classmap, so that a
 * site without Elementor cannot reach it by accident.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Integrations;

use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Permissions;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Values;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
	return;
}

/**
 * Returns a formatted field value to Elementor.
 */
class ElementorTag extends \Elementor\Core\DynamicTags\Tag {

	/**
	 * Tag name.
	 */
	public function get_name(): string {
		return 'wpcmb-field';
	}

	/**
	 * Tag title, as shown in the picker.
	 */
	public function get_title(): string {
		return __( 'Custom Meta Box Field', 'wp-custom-meta-box' );
	}

	/**
	 * Tag group.
	 *
	 * @return string
	 */
	public function get_group(): string {
		return Elementor::GROUP;
	}

	/**
	 * Where this tag may be used.
	 *
	 * Text and URL only. A tag that claimed to satisfy Elementor's image or
	 * gallery categories would have to return their exact array shapes, and
	 * offering a control that then renders nothing is worse than not offering
	 * it — a site can bind an image field's URL through the URL category.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return array(
			\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
			\Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
		);
	}

	/**
	 * The tag's own controls.
	 */
	protected function register_controls(): void {
		$this->add_control(
			'wpcmb_field',
			array(
				'label'   => __( 'Field', 'wp-custom-meta-box' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'groups'  => $this->field_groups(),
				'default' => '',
			)
		);
	}

	/**
	 * Print the value.
	 */
	public function render(): void {
		$name = (string) $this->get_settings( 'wpcmb_field' );

		if ( '' === $name ) {
			return;
		}

		$ref = ObjectRef::from( null );

		if ( ! Permissions::can_read( $ref ) ) {
			return;
		}

		$value = wpcmb()->container()->get( Values::class )->get( $name, $ref );

		if ( is_array( $value ) ) {
			// A link binds to its URL, which is the only structured value
			// with an obvious single-string meaning.
			$value = isset( $value['url'] ) && is_scalar( $value['url'] ) ? $value['url'] : '';
		}

		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '';
		}

		if ( ! is_scalar( $value ) ) {
			return;
		}

		// Elementor escapes according to where the tag was placed, so the
		// raw value is what it expects to receive here.
		echo wp_kses_post( (string) $value );
	}

	/**
	 * The field choices, grouped by field group.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function field_groups(): array {
		$groups = array();

		foreach ( wpcmb()->container()->get( Repository::class )->all() as $group ) {
			$options = array();

			foreach ( $group->fields as $field ) {
				if ( is_array( $field ) && ! empty( $field['name'] ) ) {
					$options[ (string) $field['name'] ] = (string) ( $field['label'] ?? $field['name'] );
				}
			}

			if ( array() === $options ) {
				continue;
			}

			$groups[] = array(
				'label'   => '' !== $group->title ? $group->title : __( 'Fields', 'wp-custom-meta-box' ),
				'options' => $options,
			);
		}

		return $groups;
	}
}
