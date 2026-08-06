<?php
/**
 * Editor field types.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Fields that edit a body of markup or code.
 *
 * The rich text field is `wp_editor()`, and the code, JSON and HTML fields
 * are `wp_enqueue_code_editor()` over a plain textarea. Both ship with
 * WordPress, so there is no bundled TinyMCE build and no CodeMirror copy to
 * keep patched — and the rich editor inherits whatever buttons, formats and
 * plugins the site has already configured.
 *
 * Escaping differs sharply between these types and is the reason they share
 * a class: getting `wysiwyg` wrong stores markup a subscriber can inject,
 * and getting `html` wrong strips the markup an administrator meant to save.
 */
final class Editor extends FieldType {

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'wysiwyg' => __( 'Rich Text', 'wp-custom-meta-box' ),
			'code'    => __( 'Code', 'wp-custom-meta-box' ),
			'json'    => __( 'JSON', 'wp-custom-meta-box' ),
			'html'    => __( 'HTML', 'wp-custom-meta-box' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Content', 'wp-custom-meta-box' );
	}

	/**
	 * The Dashicon shown beside a field of this type.
	 *
	 * @param string $type The specific type.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'code'    => 'dashicons-editor-code',
			'html'    => 'dashicons-html',
			'json'    => 'dashicons-media-code',
			'wysiwyg' => 'dashicons-editor-paragraph',
		);

		return $icons[ $type ] ?? 'dashicons-editor-paragraph';
	}

	/**
	 * Render the editor.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$type    = (string) ( $field['type'] ?? 'code' );
		$content = is_scalar( $value ) ? (string) $value : '';

		if ( 'wysiwyg' === $type ) {
			wp_editor(
				$content,
				// wp_editor() needs a lowercase, alphanumeric id and uses it
				// as the textarea name unless told otherwise.
				preg_replace( '/[^a-z0-9]/', '', strtolower( $input_id ) ) ?? 'wpcmbeditor',
				array(
					'textarea_name' => $input_name,
					'media_buttons' => (bool) $this->setting( $field, 'media_buttons', true ),
					'teeny'         => 'teeny' === $this->setting( $field, 'toolbar', 'full' ),
					'tinymce'       => 'text' !== $this->setting( $field, 'tabs', 'all' ),
					'quicktags'     => 'visual' !== $this->setting( $field, 'tabs', 'all' ),
					'textarea_rows' => (int) $this->setting( $field, 'rows', 10 ),
				)
			);

			return;
		}

		$attributes = $this->attributes(
			array(
				'name'            => $input_name,
				'id'              => $input_id,
				'class'           => 'wpcmb-input wpcmb-code',
				'rows'            => (string) $this->setting( $field, 'rows', '10' ),
				'spellcheck'      => 'false',
				'required'        => ! empty( $field['required'] ),
				'data-wpcmb-code' => $this->mode( $type ),
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.
		printf( '<textarea %s>%s</textarea>', $attributes, esc_textarea( $content ) );
	}

	/**
	 * Clean submitted content.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return string
	 */
	public function sanitize( mixed $value, array $field ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		return match ( (string) ( $field['type'] ?? '' ) ) {
			// Rich text is authored by whoever can edit the object, so it is
			// filtered against that user's own allowed markup — the same rule
			// the post editor applies to post content.
			'wysiwyg' => wp_kses_post( $value ),

			// The HTML field exists to hold markup kses would strip, so it is
			// restricted to users trusted with unfiltered markup and falls
			// back to post-level filtering for everyone else.
			'html'    => current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value ),

			// Code and JSON are never rendered as markup by this plugin, and
			// are stored verbatim so that a stray angle bracket survives.
			default   => $value,
		};
	}

	/**
	 * Prepare content for output.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		$type = (string) ( $field['type'] ?? '' );

		if ( 'json' === $type && ! empty( $this->setting( $field, 'decode', '' ) ) ) {
			$decoded = json_decode( $value, true );

			return null === $decoded ? $value : $decoded;
		}

		if ( 'wysiwyg' === $type && 'raw' !== $this->setting( $field, 'return_format', 'html' ) ) {
			// Runs the same filters the_content() does, so shortcodes,
			// embeds and paragraph handling behave as authors expect.
			return apply_filters( 'the_content', $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Deliberately reusing core's content filters.
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
		$schema = array(
			'rows' => array(
				'label' => __( 'Rows', 'wp-custom-meta-box' ),
				'type'  => 'number',
			),
		);

		if ( 'wysiwyg' === $type ) {
			$schema['toolbar']       = array(
				'label'   => __( 'Toolbar', 'wp-custom-meta-box' ),
				'type'    => 'select',
				'choices' => array(
					'full'  => __( 'Full', 'wp-custom-meta-box' ),
					'teeny' => __( 'Minimal', 'wp-custom-meta-box' ),
				),
			);
			$schema['tabs']          = array(
				'label'   => __( 'Tabs', 'wp-custom-meta-box' ),
				'type'    => 'select',
				'choices' => array(
					'all'    => __( 'Visual and Text', 'wp-custom-meta-box' ),
					'visual' => __( 'Visual only', 'wp-custom-meta-box' ),
					'text'   => __( 'Text only', 'wp-custom-meta-box' ),
				),
			);
			$schema['media_buttons'] = array(
				'label' => __( 'Media buttons', 'wp-custom-meta-box' ),
				'type'  => 'toggle',
			);
			$schema['return_format'] = array(
				'label'   => __( 'Return format', 'wp-custom-meta-box' ),
				'type'    => 'select',
				'choices' => array(
					'html' => __( 'Apply content filters', 'wp-custom-meta-box' ),
					'raw'  => __( 'Raw stored value', 'wp-custom-meta-box' ),
				),
			);
		}

		if ( 'json' === $type ) {
			$schema['decode'] = array(
				'label' => __( 'Return decoded array', 'wp-custom-meta-box' ),
				'type'  => 'toggle',
			);
		}

		return $schema;
	}

	/**
	 * The CodeMirror mode for a type.
	 *
	 * @param string $type Type name.
	 */
	private function mode( string $type ): string {
		return match ( $type ) {
			'json' => 'application/json',
			'html' => 'text/html',
			default => 'text/plain',
		};
	}
}
