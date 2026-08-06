<?php
/**
 * Block registration and rendering.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Blocks;

use WPCMB\Abstracts\Module;
use WPCMB\Admin\Assets;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;
use WPCMB\REST\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Turns field groups into blocks.
 *
 * A block's values live in its own attributes, not in post meta. That is what
 * makes a block portable: copy it to another post, another site, or a
 * synced pattern and its content travels with it. Meta would tie it to the
 * object it was first placed on.
 *
 * The editor UI is the same PHP-rendered form the admin screens use, fetched
 * over AJAX and injected into the block. That means a field type works in the
 * block editor the moment it works anywhere else — no parallel React
 * implementation of fifty field types, and no build step in this plugin.
 */
final class BlockRegistry extends Module {

	/**
	 * Block namespace.
	 */
	public const NAMESPACE = 'wpcmb';

	/**
	 * Block category slug.
	 */
	public const CATEGORY = 'wpcmb';

	/**
	 * The group whose block is currently rendering.
	 *
	 * Values from the block's attributes are served to the template functions
	 * while this is set, so `wpcmb_get_field()` works inside a block template
	 * exactly as it does in a theme file.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $rendering = null;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register' ), 25 );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		add_filter( 'wpcmb/value/pre_get', array( $this, 'block_value' ), 10, 2 );
	}

	/**
	 * Register a block for every group that asks to be one.
	 */
	public function register(): void {
		foreach ( $this->blocks() as $group ) {
			$this->register_block( $group );
		}
	}

	/**
	 * Field groups configured as blocks, keyed by full block name.
	 *
	 * @return array<string, FieldGroup>
	 */
	public function blocks(): array {
		$blocks = array();

		foreach ( $this->container->get( Repository::class )->all() as $group ) {
			$name = (string) ( $group->settings['block_name'] ?? '' );

			if ( empty( $group->settings['block_enabled'] ) || '' === $name ) {
				continue;
			}

			$blocks[ self::NAMESPACE . '/' . str_replace( '_', '-', $name ) ] = $group;
		}

		return $blocks;
	}

	/**
	 * Register one block.
	 *
	 * @param FieldGroup $group Field group.
	 */
	private function register_block( FieldGroup $group ): void {
		$settings = $group->settings;
		$name     = self::NAMESPACE . '/' . str_replace( '_', '-', (string) $settings['block_name'] );

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			return;
		}

		register_block_type(
			$name,
			array(
				'api_version'     => 3,
				'title'           => '' !== $group->title ? $group->title : (string) $settings['block_name'],
				'description'     => (string) $settings['block_description'],
				'category'        => '' !== (string) $settings['block_category'] ? (string) $settings['block_category'] : self::CATEGORY,
				'icon'            => '' !== (string) $settings['block_icon'] ? (string) $settings['block_icon'] : 'feedback',
				'keywords'        => array_values( array_filter( array_map( 'trim', explode( ',', (string) $settings['block_keywords'] ) ) ) ),
				'supports'        => $this->supports( $settings ),
				'attributes'      => $this->attributes( $group ),
				'render_callback' => fn( $attributes, $content, $block ) => $this->render( $group, (array) $attributes, (string) $content, $block ),
				'editor_script'   => 'wpcmb-blocks',
				'editor_style'    => 'wpcmb-fields',
			)
		);
	}

	/**
	 * The block's attribute schema.
	 *
	 * Field values sit under one `data` attribute rather than one attribute
	 * per field, so renaming or adding a field does not invalidate blocks
	 * already saved in content — the editor only warns about attributes it
	 * knows are missing.
	 *
	 * @param FieldGroup $group Field group.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function attributes( FieldGroup $group ): array {
		$properties = array();

		foreach ( $group->fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['name'] ) && Schema::is_exposable( $field ) ) {
				$properties[ (string) $field['name'] ] = Schema::for_field( $field );
			}
		}

		return array(
			'data' => array(
				'type'       => 'object',
				'default'    => array(),
				'properties' => $properties,
			),
			'mode' => array(
				'type'    => 'string',
				'enum'    => array( 'auto', 'preview', 'edit' ),
				'default' => (string) ( $group->settings['block_mode'] ?? 'auto' ),
			),
		);
	}

	/**
	 * Map the group's chosen supports to a block supports array.
	 *
	 * @param array<string, mixed> $settings Group settings.
	 *
	 * @return array<string, mixed>
	 */
	private function supports( array $settings ): array {
		$chosen = (array) ( $settings['block_supports'] ?? array() );

		$supports = array(
			'align'           => in_array( 'align', $chosen, true ),
			'anchor'          => in_array( 'anchor', $chosen, true ),
			'customClassName' => in_array( 'custom_class_name', $chosen, true ),
			'html'            => false,
		);

		foreach ( array( 'color', 'spacing', 'typography' ) as $feature ) {
			if ( in_array( $feature, $chosen, true ) ) {
				$supports[ $feature ] = true;
			}
		}

		return $supports;
	}

	/**
	 * Render a block.
	 *
	 * Attributes arrive from the editor and are cleaned by their own field
	 * types before anything is output — the same sanitizers the edit screens,
	 * the front-end forms and REST use, so a block cannot become a way to
	 * store something the other three would have refused.
	 *
	 * @param FieldGroup           $group      Field group.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Inner blocks output.
	 * @param mixed                $block      Block instance.
	 */
	public function render( FieldGroup $group, array $attributes, string $content, mixed $block ): string {
		$renderer = $this->container->get( Renderer::class );
		$raw      = is_array( $attributes['data'] ?? null ) ? $attributes['data'] : array();
		$fields   = array();

		foreach ( $group->fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) || ! $renderer->stores_value( $field ) ) {
				continue;
			}

			$name            = (string) $field['name'];
			$clean           = $renderer->sanitize( $raw[ $name ] ?? null, $field );
			$fields[ $name ] = $renderer->format( $clean, $field );
		}

		$context = array(
			'group'      => $group,
			'fields'     => $fields,
			'attributes' => $attributes,
			'content'    => $content,
			'block'      => $block,
			'is_preview' => ! empty( $attributes['__wpcmbPreview'] ),
			'class_name' => trim( 'wpcmb-block wpcmb-block--' . sanitize_html_class( (string) $group->settings['block_name'] ) ),
		);

		// Serve the block's own values to wpcmb_get_field() for the duration
		// of the template, so a block template reads like a theme template.
		$this->rendering = $fields;

		ob_start();

		/**
		 * Filters the template used to render a block.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $template Absolute path to the template.
		 * @param array<string, mixed> $context  Template context.
		 */
		$template = (string) apply_filters( 'wpcmb/block/template', $this->locate_template( $group ), $context );

		if ( is_readable( $template ) ) {
			include $template;
		}

		$this->rendering = null;

		return (string) ob_get_clean();
	}

	/**
	 * Supply a block's own value to the template functions.
	 *
	 * @param mixed  $value Null to read from storage.
	 * @param string $name  Field name.
	 *
	 * @return mixed
	 */
	public function block_value( $value, $name = '' ) {
		if ( null === $this->rendering ) {
			return $value;
		}

		return $this->rendering[ (string) $name ] ?? $value;
	}

	/**
	 * The template for a block, preferring one supplied by the theme.
	 *
	 * A theme's own file wins, then a per-block file shipped by a plugin,
	 * then the generic fallback — so a block renders something sensible from
	 * the moment it is created and can be styled properly later.
	 *
	 * @param FieldGroup $group Field group.
	 */
	private function locate_template( FieldGroup $group ): string {
		$name = str_replace( '_', '-', (string) $group->settings['block_name'] );

		$theme = locate_template(
			array(
				'wp-custom-meta-box/blocks/' . $name . '.php',
				'wp-custom-meta-box/block.php',
			)
		);

		if ( '' !== $theme ) {
			return $theme;
		}

		$shipped = WPCMB_DIR . 'templates/blocks/' . $name . '.php';

		return is_readable( $shipped ) ? $shipped : WPCMB_DIR . 'templates/block.php';
	}

	/**
	 * Add the plugin's block category.
	 *
	 * @param array<int, array<string, mixed>> $categories Existing categories.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function category( $categories ): array {
		$categories = is_array( $categories ) ? $categories : array();

		foreach ( $categories as $category ) {
			if ( self::CATEGORY === ( $category['slug'] ?? '' ) ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => self::CATEGORY,
			'title' => __( 'Custom Meta Box', 'wp-custom-meta-box' ),
			'icon'  => null,
		);

		return $categories;
	}

	/**
	 * Register the editor script and hand it what it needs.
	 */
	public function editor_assets(): void {
		$blocks = array();

		foreach ( $this->blocks() as $name => $group ) {
			$blocks[ $name ] = array(
				'group'       => $group->key,
				'mode'        => (string) ( $group->settings['block_mode'] ?? 'auto' ),
				'innerBlocks' => ! empty( $group->settings['block_inner_blocks'] ),
			);
		}

		if ( array() === $blocks ) {
			return;
		}

		wp_enqueue_script(
			'wpcmb-blocks',
			WPCMB_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			Assets::version( 'assets/js/blocks.js' ),
			true
		);

		wp_enqueue_style( 'wpcmb-fields', WPCMB_URL . 'assets/css/fields.css', array(), Assets::version( 'assets/css/fields.css' ) );

		wp_localize_script(
			'wpcmb-blocks',
			'wpcmbBlocks',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( \WPCMB\Admin\Ajax::NONCE ),
				'blocks'  => $blocks,
				'i18n'    => array(
					'edit'    => __( 'Edit', 'wp-custom-meta-box' ),
					'preview' => __( 'Preview', 'wp-custom-meta-box' ),
					'loading' => __( 'Loading fields…', 'wp-custom-meta-box' ),
					'failed'  => __( 'The fields could not be loaded.', 'wp-custom-meta-box' ),
					'empty'   => __( 'This block has no fields yet.', 'wp-custom-meta-box' ),
				),
			)
		);

		wp_set_script_translations( 'wpcmb-blocks', 'wp-custom-meta-box', WPCMB_DIR . 'languages' );
	}

	/**
	 * Render a block group's fields as a form, for the editor.
	 *
	 * @param FieldGroup           $group  Field group.
	 * @param array<string, mixed> $values Current values.
	 */
	public function render_form( FieldGroup $group, array $values ): string {
		$renderer = $this->container->get( Renderer::class );

		// Values come from the block, not from an object, so the renderer is
		// pointed at nothing addressable and fed through the same filter the
		// front-end render uses.
		$this->rendering = $values;

		ob_start();
		$renderer->group( $group, new ObjectRef( ObjectRef::POST, 0 ) );
		$this->rendering = null;

		return (string) ob_get_clean();
	}
}
