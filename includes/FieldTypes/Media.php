<?php
/**
 * Media field types.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Fields that reference attachments: file, image, gallery, video and audio.
 *
 * All of them store attachment ids and open the WordPress media library,
 * which already provides upload, drag and drop, multi-select, sorting, alt
 * text, captions and image editing. Reimplementing any of that would produce
 * a second, worse media library that does not share the user's uploads.
 *
 * The markup is a hidden input holding a comma-separated id list plus a
 * preview list; the script upgrades it to a media picker. Without JavaScript
 * the ids remain editable and the value survives a save.
 */
final class Media extends FieldType {

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'file'    => __( 'File', 'wp-custom-meta-box' ),
			'image'   => __( 'Image', 'wp-custom-meta-box' ),
			'gallery' => __( 'Gallery', 'wp-custom-meta-box' ),
			'video'   => __( 'Video', 'wp-custom-meta-box' ),
			'audio'   => __( 'Audio', 'wp-custom-meta-box' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Media', 'wp-custom-meta-box' );
	}

	/**
	 * The Dashicon shown beside a field of this type.
	 *
	 * @param string $type The specific type.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'audio'   => 'dashicons-format-audio',
			'file'    => 'dashicons-media-default',
			'gallery' => 'dashicons-images-alt2',
			'image'   => 'dashicons-format-image',
			'video'   => 'dashicons-format-video',
		);

		return $icons[ $type ] ?? 'dashicons-media-default';
	}

	/**
	 * Render the picker.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$type     = (string) ( $field['type'] ?? 'file' );
		$multiple = 'gallery' === $type;
		$ids      = $this->ids( $value );

		printf(
			'<div class="wpcmb-media" data-wpcmb-media="%s" data-wpcmb-multiple="%s" data-wpcmb-mime="%s">',
			esc_attr( $type ),
			esc_attr( $multiple ? '1' : '0' ),
			esc_attr( $this->mime( $type ) )
		);

		printf(
			'<input type="hidden" class="wpcmb-media__ids" name="%s" id="%s" value="%s"%s />',
			esc_attr( $input_name ),
			esc_attr( $input_id ),
			esc_attr( implode( ',', $ids ) ),
			! empty( $field['required'] ) ? ' required' : ''
		);

		echo '<ul class="wpcmb-media__list">';

		foreach ( $ids as $id ) {
			$this->render_item( $id );
		}

		echo '</ul>';

		printf(
			'<p class="wpcmb-media__actions">
				<button type="button" class="button wpcmb-media__select">%s</button>
				<button type="button" class="button-link-delete wpcmb-media__clear">%s</button>
			</p>
			</div>',
			esc_html( $multiple ? __( 'Add media', 'wp-custom-meta-box' ) : __( 'Select media', 'wp-custom-meta-box' ) ),
			esc_html( __( 'Clear', 'wp-custom-meta-box' ) )
		);
	}

	/**
	 * Render one attachment preview.
	 *
	 * @param int $id Attachment id.
	 */
	private function render_item( int $id ): void {
		$title = get_the_title( $id );
		$thumb = wp_get_attachment_image( $id, 'thumbnail', true, array( 'loading' => 'lazy' ) );

		printf(
			'<li class="wpcmb-media__item" data-wpcmb-id="%s">%s<span class="wpcmb-media__title">%s</span>
			<button type="button" class="wpcmb-media__remove button-link-delete" aria-label="%s">&times;</button></li>',
			esc_attr( (string) $id ),
			wp_kses_post( $thumb ),
			esc_html( '' !== $title ? $title : (string) $id ),
			esc_attr__( 'Remove', 'wp-custom-meta-box' )
		);
	}

	/**
	 * Keep only ids that refer to attachments the current user may see.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return int|array<int, int>|string
	 */
	public function sanitize( mixed $value, array $field ): int|array|string {
		$multiple = 'gallery' === ( $field['type'] ?? '' );

		$ids = array_values(
			array_filter(
				$this->ids( $value ),
				static fn( int $id ): bool => 'attachment' === get_post_type( $id )
			)
		);

		if ( $multiple ) {
			return $ids;
		}

		return $ids[0] ?? '';
	}

	/**
	 * Turn stored ids into whatever the field is configured to return.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		$multiple = 'gallery' === ( $field['type'] ?? '' );
		$ids      = $this->ids( $value );
		$format   = (string) $this->setting( $field, 'return_format', 'id' );

		$mapped = array_map(
			fn( int $id ) => match ( $format ) {
				'url'    => wp_get_attachment_url( $id ),
				'array'  => $this->attachment_array( $id ),
				'object' => get_post( $id ),
				default  => $id,
			},
			$ids
		);

		if ( $multiple ) {
			return $mapped;
		}

		return $mapped[0] ?? ( 'id' === $format ? 0 : null );
	}

	/**
	 * A plain array describing an attachment.
	 *
	 * @param int $id Attachment id.
	 *
	 * @return array<string, mixed>
	 */
	private function attachment_array( int $id ): array {
		return array(
			'id'      => $id,
			'url'     => wp_get_attachment_url( $id ),
			'alt'     => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'   => get_the_title( $id ),
			'caption' => wp_get_attachment_caption( $id ),
			'mime'    => get_post_mime_type( $id ),
			'sizes'   => wp_get_attachment_metadata( $id )['sizes'] ?? array(),
		);
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		$schema = array(
			'return_format' => array(
				'label'   => __( 'Return format', 'wp-custom-meta-box' ),
				'type'    => 'select',
				'choices' => array(
					'id'     => __( 'Attachment ID', 'wp-custom-meta-box' ),
					'url'    => __( 'URL', 'wp-custom-meta-box' ),
					'array'  => __( 'Array', 'wp-custom-meta-box' ),
					'object' => __( 'WP_Post object', 'wp-custom-meta-box' ),
				),
			),
		);

		if ( 'gallery' === $type ) {
			$schema['min'] = array(
				'label' => __( 'Minimum items', 'wp-custom-meta-box' ),
				'type'  => 'number',
			);
			$schema['max'] = array(
				'label' => __( 'Maximum items', 'wp-custom-meta-box' ),
				'type'  => 'number',
			);
		}

		return $schema;
	}

	/**
	 * The media library filter for a type.
	 *
	 * @param string $type Type name.
	 */
	private function mime( string $type ): string {
		return match ( $type ) {
			'image', 'gallery' => 'image',
			'video'            => 'video',
			'audio'            => 'audio',
			default            => '',
		};
	}

	/**
	 * Normalise a value to a list of positive integer ids.
	 *
	 * Accepts the comma-separated string the control posts, an array from
	 * code, or a single id.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array<int, int>
	 */
	private function ids( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = '' === $value ? array() : explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		return array_values(
			array_filter(
				array_map( 'absint', array_filter( $value, 'is_scalar' ) ),
				static fn( int $id ): bool => $id > 0
			)
		);
	}
}
