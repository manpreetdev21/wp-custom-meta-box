<?php
/**
 * Script-enhanced field types.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Fields whose control is a plain input that a script upgrades in place.
 *
 * Icon, map, signature, QR, barcode, embed and address all store text and
 * are drawn as a real form control plus an empty container the script fills.
 * Building them this way means each one degrades to something usable without
 * JavaScript, and none of them needs a bundled third-party library at the
 * PHP layer — the enhancement is opt-in per site through
 * `wpcmb/field/enhanced_config`.
 *
 * ponytail: the shipped enhancements are the ones the browser or WordPress
 * can already do (an embed preview via oEmbed, a signature via canvas). A map
 * needs a tile provider and a QR or barcode needs an encoder, so those render
 * as their stored text until a site supplies one through the filter — better
 * than shipping a key-less map that silently shows nothing.
 */
final class Enhanced extends FieldType {

	/**
	 * The parts an address is made of.
	 */
	private const ADDRESS_PARTS = array( 'line1', 'line2', 'city', 'region', 'postcode', 'country' );

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'icon'      => __( 'Icon Picker', 'wp-custom-meta-box' ),
			'map'       => __( 'Map', 'wp-custom-meta-box' ),
			'signature' => __( 'Signature', 'wp-custom-meta-box' ),
			'qr'        => __( 'QR Code', 'wp-custom-meta-box' ),
			'barcode'   => __( 'Barcode', 'wp-custom-meta-box' ),
			'embed'     => __( 'Embed', 'wp-custom-meta-box' ),
			'address'   => __( 'Address', 'wp-custom-meta-box' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Advanced', 'wp-custom-meta-box' );
	}

	/**
	 * The Dashicon shown beside a field of this type.
	 *
	 * @param string $type The specific type.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'address'   => 'dashicons-building',
			'barcode'   => 'dashicons-menu',
			'embed'     => 'dashicons-embed-generic',
			'icon'      => 'dashicons-star-empty',
			'map'       => 'dashicons-location-alt',
			'qr'        => 'dashicons-grid-view',
			'signature' => 'dashicons-edit-large',
		);

		return $icons[ $type ] ?? 'dashicons-star-empty';
	}

	/**
	 * Render the control and the container the script upgrades.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$type = (string) ( $field['type'] ?? '' );

		if ( 'address' === $type ) {
			$this->render_address( $field, $value, $input_name, $input_id );
			return;
		}

		/**
		 * Filters the configuration handed to a field's script enhancement.
		 *
		 * This is where a site supplies the map tile provider, QR encoder or
		 * icon set it wants to use.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $config Enhancement configuration.
		 * @param array<string, mixed> $field  Field definition.
		 */
		$config = (array) apply_filters( 'wpcmb/field/enhanced_config', array(), $field );

		printf(
			'<div class="wpcmb-enhanced wpcmb-enhanced--%s" data-wpcmb-enhanced="%s" data-wpcmb-config="%s">',
			esc_attr( $type ),
			esc_attr( $type ),
			esc_attr( (string) wp_json_encode( $config ) )
		);

		$attributes = $this->attributes(
			$this->base_attributes( $field, $input_name, $input_id ) + array(
				'type'  => 'embed' === $type ? 'url' : 'text',
				'value' => is_scalar( $value ) ? (string) $value : '',
			)
		);

		printf( '<input %s />', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.

		echo '<div class="wpcmb-enhanced__preview" aria-live="polite">';

		if ( 'embed' === $type && is_string( $value ) && '' !== $value ) {
			// oEmbed is already in WordPress, already caches its lookups and
			// already restricts itself to the allowed provider list.
			$oembed = wp_oembed_get( $value );

			if ( is_string( $oembed ) ) {
				echo wp_kses_post( $oembed );
			}
		}

		echo '</div></div>';
	}

	/**
	 * Render the address parts.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	private function render_address( array $field, mixed $value, string $input_name, string $input_id ): void {
		$address = $this->normalise_address( $value );

		$labels = array(
			'line1'    => __( 'Address line 1', 'wp-custom-meta-box' ),
			'line2'    => __( 'Address line 2', 'wp-custom-meta-box' ),
			'city'     => __( 'City', 'wp-custom-meta-box' ),
			'region'   => __( 'State / Region', 'wp-custom-meta-box' ),
			'postcode' => __( 'Postcode', 'wp-custom-meta-box' ),
			'country'  => __( 'Country', 'wp-custom-meta-box' ),
		);

		// The browser's own autofill hints, so a saved address fills in one
		// gesture instead of six.
		$autocomplete = array(
			'line1'    => 'address-line1',
			'line2'    => 'address-line2',
			'city'     => 'address-level2',
			'region'   => 'address-level1',
			'postcode' => 'postal-code',
			'country'  => 'country-name',
		);

		echo '<div class="wpcmb-address">';

		foreach ( self::ADDRESS_PARTS as $part ) {
			printf(
				'<p class="wpcmb-address__row"><label class="wpcmb-label" for="%1$s-%2$s">%3$s</label>
				<input type="text" class="wpcmb-input" id="%1$s-%2$s" name="%4$s[%2$s]" value="%5$s" autocomplete="%6$s" /></p>',
				esc_attr( $input_id ),
				esc_attr( $part ),
				esc_html( $labels[ $part ] ),
				esc_attr( $input_name ),
				esc_attr( $address[ $part ] ),
				esc_attr( $autocomplete[ $part ] )
			);
		}

		echo '</div>';
	}

	/**
	 * Clean a submitted value.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function sanitize( mixed $value, array $field ): mixed {
		$type = (string) ( $field['type'] ?? '' );

		if ( 'address' === $type ) {
			$address = array_map( 'sanitize_text_field', $this->normalise_address( $value ) );

			return '' === implode( '', $address ) ? '' : $address;
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		if ( 'embed' === $type ) {
			return sanitize_url( $value );
		}

		// A signature arrives as a data URI from a canvas. Only PNG data URIs
		// are kept, so the field cannot be used to smuggle a scriptable SVG
		// or an off-site URL into a page that prints it in a src attribute.
		if ( 'signature' === $type ) {
			return 1 === preg_match( '#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $value ) ? $value : '';
		}

		// A map value is a "lat,lng" pair, optionally followed by an address.
		if ( 'map' === $type ) {
			return 1 === preg_match( '/^-?\d{1,3}(\.\d+)?,\s*-?\d{1,3}(\.\d+)?/', $value ) ? sanitize_text_field( $value ) : '';
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Prepare a value for output.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		$type = (string) ( $field['type'] ?? '' );

		if ( 'map' === $type && is_string( $value ) && str_contains( $value, ',' ) ) {
			$parts = array_map( 'trim', explode( ',', $value, 3 ) );

			return array(
				'lat'     => (float) ( $parts[0] ?? 0 ),
				'lng'     => (float) ( $parts[1] ?? 0 ),
				'address' => $parts[2] ?? '',
			);
		}

		if ( 'embed' === $type && is_string( $value ) && '' !== $value
			&& 'html' === $this->setting( $field, 'return_format', 'html' )
		) {
			$html = wp_oembed_get( $value );

			return false === $html ? $value : $html;
		}

		return $value;
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		if ( 'embed' === $type ) {
			return array(
				'return_format' => array(
					'label'   => __( 'Return format', 'wp-custom-meta-box' ),
					'type'    => 'select',
					'choices' => array(
						'html' => __( 'Embed HTML', 'wp-custom-meta-box' ),
						'url'  => __( 'URL', 'wp-custom-meta-box' ),
					),
				),
			);
		}

		if ( 'icon' === $type ) {
			return array(
				'icon_set' => array(
					'label' => __( 'Icon set', 'wp-custom-meta-box' ),
					'type'  => 'text',
					'help'  => __( 'Passed to the enhancement script. Dashicons are used when empty.', 'wp-custom-meta-box' ),
				),
			);
		}

		return array();
	}

	/**
	 * Coerce any shape into the known address parts.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array<string, string>
	 */
	private function normalise_address( mixed $value ): array {
		$value   = is_array( $value ) ? $value : array();
		$address = array();

		foreach ( self::ADDRESS_PARTS as $part ) {
			$address[ $part ] = is_scalar( $value[ $part ] ?? null ) ? (string) $value[ $part ] : '';
		}

		return $address;
	}
}
