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
 * Icon, map, signature, QR, barcode, embed and address all store one value and
 * are drawn as real markup that enhanced.js binds to. The value always lives in
 * an input the server wrote, so a control whose script never runs still carries
 * what was stored through a save rather than blanking it.
 *
 * Nothing here loads from another host. The QR and barcode encoders are the
 * plugin's own, the embed preview goes through WordPress's oEmbed rather than a
 * provider directly, and the icon list is read out of core's Dashicons
 * stylesheet. `wpcmb/field/enhanced_config` stays available for a site that
 * wants to add to any of it.
 *
 * ponytail: the map is coordinates and a link out, not tiles. Drawing a real
 * map means pulling imagery from a third party on every edit screen, and every
 * asset this plugin loads is its own.
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
		$stored = is_scalar( $value ) ? (string) $value : '';

		printf(
			'<div class="wpcmb-enhanced wpcmb-enhanced--%s" data-wpcmb-enhanced="%s" data-wpcmb-config="%s">',
			esc_attr( $type ),
			esc_attr( $type ),
			esc_attr( (string) wp_json_encode( $config ) )
		);

		// The icon, map and signature controls are built around the stored
		// value rather than beside it, so the text input becomes a hidden
		// carrier and the visible controls are the script's. Everything else
		// keeps a real input that works on its own.
		if ( in_array( $type, array( 'icon', 'map', 'signature' ), true ) ) {
			printf(
				'<input type="hidden" name="%s" id="%s" class="wpcmb-enhanced__value" value="%s" />',
				esc_attr( $input_name ),
				esc_attr( $input_id ),
				esc_attr( $stored )
			);

			$this->render_control( $type, $input_id, $stored );
		} else {
			$attributes = $this->attributes(
				$this->base_attributes( $field, $input_name, $input_id ) + array(
					'type'  => 'embed' === $type ? 'url' : 'text',
					'value' => $stored,
				)
			);

			printf( '<input %s />', $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.
		}

		echo '<div class="wpcmb-enhanced__preview" aria-live="polite">';

		if ( 'embed' === $type && '' !== $stored ) {
			// oEmbed is already in WordPress, already caches its lookups and
			// already restricts itself to the allowed provider list.
			$oembed = wp_oembed_get( $stored );

			if ( is_string( $oembed ) ) {
				echo wp_kses_post( $oembed );
			}
		}

		echo '</div></div>';
	}

	/**
	 * Render the visible half of a control whose value lives in a hidden input.
	 *
	 * Everything here is inert markup: the script binds to it, and with the
	 * script absent the hidden input above still carries the stored value
	 * through a save untouched, so editing is unavailable but nothing is lost.
	 *
	 * @param string $type     Type name.
	 * @param string $input_id Input id.
	 * @param string $stored   Stored value.
	 */
	private function render_control( string $type, string $input_id, string $stored ): void {
		if ( 'icon' === $type ) {
			printf(
				'<div class="wpcmb-icon">
					<span class="wpcmb-icon__current" aria-live="polite">%4$s</span>
					<button type="button" class="button wpcmb-icon__choose" aria-expanded="false" aria-controls="%1$s-picker">%2$s</button>
					<button type="button" class="button-link wpcmb-icon__clear"%5$s>%3$s</button>
					<div class="wpcmb-icon__picker" id="%1$s-picker" hidden></div>
				</div>',
				esc_attr( $input_id ),
				esc_html__( 'Choose icon', 'wp-custom-meta-box' ),
				esc_html__( 'Clear', 'wp-custom-meta-box' ),
				$this->icon_preview( $stored ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in icon_preview().
				'' === $stored ? ' hidden' : ''
			);

			return;
		}

		if ( 'signature' === $type ) {
			printf(
				'<div class="wpcmb-signature">
					<canvas class="wpcmb-signature__pad" width="600" height="200" role="img" aria-label="%1$s"></canvas>
					<p class="wpcmb-signature__actions">
						<button type="button" class="button wpcmb-signature__clear">%2$s</button>
						<span class="wpcmb-signature__hint">%3$s</span>
					</p>
				</div>',
				esc_attr__( 'Signature drawing area', 'wp-custom-meta-box' ),
				esc_html__( 'Clear', 'wp-custom-meta-box' ),
				esc_html__( 'Draw with a mouse, pen or finger.', 'wp-custom-meta-box' )
			);

			return;
		}

		$parts = array_map( 'trim', explode( ',', $stored, 3 ) );

		printf(
			'<div class="wpcmb-map">
				<p class="wpcmb-map__row">
					<label for="%1$s-lat">%2$s</label>
					<input type="number" step="any" min="-90" max="90" class="wpcmb-input wpcmb-map__lat" id="%1$s-lat" value="%5$s" />
				</p>
				<p class="wpcmb-map__row">
					<label for="%1$s-lng">%3$s</label>
					<input type="number" step="any" min="-180" max="180" class="wpcmb-input wpcmb-map__lng" id="%1$s-lng" value="%6$s" />
				</p>
				<p class="wpcmb-map__row wpcmb-map__row--wide">
					<label for="%1$s-address">%4$s</label>
					<input type="text" class="wpcmb-input wpcmb-map__address" id="%1$s-address" value="%7$s" autocomplete="street-address" />
				</p>
				<p class="wpcmb-map__actions">
					<button type="button" class="button wpcmb-map__locate" hidden>%8$s</button>
					<a class="wpcmb-map__open" href="#" target="_blank" rel="noopener noreferrer" hidden>%9$s</a>
				</p>
			</div>',
			esc_attr( $input_id ),
			esc_html__( 'Latitude', 'wp-custom-meta-box' ),
			esc_html__( 'Longitude', 'wp-custom-meta-box' ),
			esc_html__( 'Address', 'wp-custom-meta-box' ),
			esc_attr( $parts[0] ?? '' ),
			esc_attr( $parts[1] ?? '' ),
			esc_attr( $parts[2] ?? '' ),
			esc_html__( 'Use my location', 'wp-custom-meta-box' ),
			esc_html__( 'Open in maps', 'wp-custom-meta-box' )
		);
	}

	/**
	 * The markup showing whichever of the three icon kinds is stored.
	 *
	 * Rendered server-side because an attachment id on its own says nothing
	 * about where the file is: the browser would have to ask before it could
	 * draw anything, and the answer is already here.
	 *
	 * @param string $stored Stored value.
	 */
	private function icon_preview( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		if ( 1 === preg_match( '/^dashicons-[a-z0-9-]+$/', $stored ) ) {
			return sprintf(
				'<span class="dashicons %s wpcmb-icon__glyph" aria-hidden="true"></span>',
				esc_attr( $stored )
			);
		}

		if ( ctype_digit( $stored ) ) {
			$image = wp_get_attachment_image( (int) $stored, array( 32, 32 ), true );

			return is_string( $image ) ? $image : '';
		}

		return sprintf( '<img src="%s" alt="" width="32" height="32" />', esc_url( $stored ) );
	}

	/**
	 * Every Dashicon name the installed WordPress ships.
	 *
	 * Read out of core's own stylesheet rather than bundled as a list: a
	 * hard-coded copy goes stale the first time core adds an icon, and there
	 * is no PHP-side list in core to call instead. Cached for a day, since the
	 * answer only changes when WordPress itself is updated.
	 *
	 * @return array<int, string>
	 */
	public static function dashicons(): array {
		$cached = get_transient( 'wpcmb_dashicons' );

		if ( is_array( $cached ) && array() !== $cached ) {
			return $cached;
		}

		$css   = ABSPATH . WPINC . '/css/dashicons.css';
		$names = array();

		if ( is_readable( $css ) ) {
			$source = (string) file_get_contents( $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local core file, not a remote request.

			preg_match_all( '/\.(dashicons-[a-z0-9-]+):before/', $source, $matches );

			$names = array_values( array_unique( $matches[1] ) );
			sort( $names );
		}

		set_transient( 'wpcmb_dashicons', $names, DAY_IN_SECONDS );

		return $names;
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

		// An icon is one of three things and nothing else: a Dashicon name, an
		// attachment id, or a URL. Anything that is none of them is discarded
		// rather than stored, because the value ends up in a class attribute
		// or a src attribute wherever the theme prints it.
		if ( 'icon' === $type ) {
			if ( 1 === preg_match( '/^dashicons-[a-z0-9-]+$/', $value ) ) {
				return $value;
			}

			if ( ctype_digit( $value ) ) {
				return $value;
			}

			// An image reference and nothing else. sanitize_url() alone is too
			// loose here: it prepends a scheme to arbitrary text rather than
			// rejecting it, so "not a url" comes back as "http://not%20a%20url"
			// and lands in a src attribute. The value is only ever an icon, so
			// http(s) or a site-relative path is the whole allowed set.
			$url = sanitize_url( $value );

			if ( '' === $url || 1 === preg_match( '/\s/', $value ) ) {
				return '';
			}

			return 1 === preg_match( '#^(https?://|/)#i', $url ) ? $url : '';
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
