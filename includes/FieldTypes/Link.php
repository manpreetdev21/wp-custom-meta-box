<?php
/**
 * Link field type.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * A URL, its link text and whether it opens in a new tab.
 *
 * Stored as one array under one meta key rather than three separate fields,
 * because the three parts are meaningless apart: a link with a URL and no
 * text is not half a link, it is a broken one.
 */
final class Link extends FieldType {

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array( 'link' => __( 'Link', 'wp-custom-meta-box' ) );
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
			'link' => 'dashicons-admin-links',
		);

		return $icons[ $type ] ?? 'dashicons-admin-links';
	}

	/**
	 * Render the three parts.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$link = $this->normalise( $value );

		echo '<div class="wpcmb-link">';

		printf(
			'<p class="wpcmb-link__row"><label class="wpcmb-label" for="%1$s-url">%2$s</label>
			<input type="url" class="wpcmb-input" id="%1$s-url" name="%3$s[url]" value="%4$s" placeholder="https://"%5$s /></p>',
			esc_attr( $input_id ),
			esc_html__( 'URL', 'wp-custom-meta-box' ),
			esc_attr( $input_name ),
			esc_attr( $link['url'] ),
			! empty( $field['required'] ) ? ' required' : ''
		);

		printf(
			'<p class="wpcmb-link__row"><label class="wpcmb-label" for="%1$s-title">%2$s</label>
			<input type="text" class="wpcmb-input" id="%1$s-title" name="%3$s[title]" value="%4$s" /></p>',
			esc_attr( $input_id ),
			esc_html__( 'Link text', 'wp-custom-meta-box' ),
			esc_attr( $input_name ),
			esc_attr( $link['title'] )
		);

		printf(
			'<p class="wpcmb-link__row"><label class="wpcmb-checkbox">
			<input type="hidden" name="%1$s[target]" value="" />
			<input type="checkbox" name="%1$s[target]" value="_blank"%2$s /> %3$s</label></p>',
			esc_attr( $input_name ),
			'_blank' === $link['target'] ? ' checked' : '',
			esc_html__( 'Open in a new tab', 'wp-custom-meta-box' )
		);

		echo '</div>';
	}

	/**
	 * Clean the submitted parts.
	 *
	 * A link with no URL is stored as nothing at all, so that an emptied
	 * field reads back as empty rather than as an array of blank strings.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>|string
	 */
	public function sanitize( mixed $value, array $field ): array|string {
		$link = $this->normalise( $value );
		$url  = sanitize_url( $link['url'] );

		if ( '' === $url ) {
			return '';
		}

		return array(
			'url'    => $url,
			'title'  => sanitize_text_field( $link['title'] ),
			'target' => '_blank' === $link['target'] ? '_blank' : '',
		);
	}

	/**
	 * Return the whole link, or just its URL.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		if ( '' === $value || null === $value ) {
			return $value;
		}

		$link = $this->normalise( $value );

		return 'url' === $this->setting( $field, 'return_format', 'array' ) ? $link['url'] : $link;
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		return array(
			'return_format' => array(
				'label'   => __( 'Return format', 'wp-custom-meta-box' ),
				'type'    => 'select',
				'choices' => array(
					'array' => __( 'Array of url, title and target', 'wp-custom-meta-box' ),
					'url'   => __( 'URL only', 'wp-custom-meta-box' ),
				),
			),
		);
	}

	/**
	 * Coerce any stored or submitted shape into the three known parts.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array{url: string, title: string, target: string}
	 */
	private function normalise( mixed $value ): array {
		if ( is_string( $value ) ) {
			return array(
				'url'    => $value,
				'title'  => '',
				'target' => '',
			);
		}

		if ( ! is_array( $value ) ) {
			return array(
				'url'    => '',
				'title'  => '',
				'target' => '',
			);
		}

		return array(
			'url'    => is_scalar( $value['url'] ?? null ) ? (string) $value['url'] : '',
			'title'  => is_scalar( $value['title'] ?? null ) ? (string) $value['title'] : '',
			'target' => is_scalar( $value['target'] ?? null ) ? (string) $value['target'] : '',
		);
	}
}
