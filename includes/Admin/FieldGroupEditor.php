<?php
/**
 * Field group editor screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the field group editor.
 *
 * The fields list and the location rules are edited by JavaScript and posted
 * as JSON in a single hidden control each; display settings are plain form
 * controls so they work without JavaScript. All three merge into one array
 * that passes through FieldGroup::sanitize() before it is stored, so there is
 * exactly one place where untrusted editor input becomes stored data.
 */
final class FieldGroupEditor extends Module {

	/**
	 * Nonce action.
	 */
	private const NONCE = 'wpcmb_save_field_group';

	/**
	 * Only load in the admin.
	 */
	public function is_enabled(): bool {
		return is_admin();
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'add_meta_boxes_' . FieldGroupPostType::POST_TYPE, array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . FieldGroupPostType::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
	}

	/**
	 * Register the editor meta boxes.
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'wpcmb-fields',
			__( 'Fields', 'wp-custom-meta-box' ),
			array( $this, 'render_fields' ),
			FieldGroupPostType::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wpcmb-location',
			__( 'Location Rules', 'wp-custom-meta-box' ),
			array( $this, 'render_location' ),
			FieldGroupPostType::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wpcmb-settings',
			__( 'Display Settings', 'wp-custom-meta-box' ),
			array( $this, 'render_settings' ),
			FieldGroupPostType::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'wpcmb-block',
			__( 'Block', 'wp-custom-meta-box' ),
			array( $this, 'render_block_settings' ),
			FieldGroupPostType::POST_TYPE,
			'side',
			'low'
		);
	}

	/**
	 * Render the block settings.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_block_settings( \WP_Post $post ): void {
		$settings = $this->group( $post )->settings;

		printf(
			'<p><label class="wpcmb-checkbox"><input type="hidden" name="wpcmb[settings][block_enabled]" value="" />
			<input type="checkbox" name="wpcmb[settings][block_enabled]" value="1"%s /> %s</label></p>',
			checked( ! empty( $settings['block_enabled'] ), true, false ),
			esc_html__( 'Register this group as a block', 'wp-custom-meta-box' )
		);

		$text = array(
			'block_name'        => array(
				__( 'Block name', 'wp-custom-meta-box' ),
				__( 'Part of your saved content. Renaming it orphans blocks already placed.', 'wp-custom-meta-box' ),
			),
			'block_icon'        => array( __( 'Icon', 'wp-custom-meta-box' ), __( 'A Dashicon name, e.g. cover-image.', 'wp-custom-meta-box' ) ),
			'block_description' => array( __( 'Description', 'wp-custom-meta-box' ), '' ),
			'block_keywords'    => array( __( 'Keywords', 'wp-custom-meta-box' ), __( 'Comma separated.', 'wp-custom-meta-box' ) ),
		);

		foreach ( $text as $name => $labels ) {
			printf(
				'<p><label class="wpcmb-label" for="wpcmb-%1$s">%2$s</label>
				<input class="widefat" type="text" id="wpcmb-%1$s" name="wpcmb[settings][%1$s]" value="%3$s" />%4$s</p>',
				esc_attr( $name ),
				esc_html( $labels[0] ),
				esc_attr( (string) ( $settings[ $name ] ?? '' ) ),
				'' !== $labels[1] ? '<span class="description">' . esc_html( $labels[1] ) . '</span>' : ''
			);
		}

		printf( '<p><label class="wpcmb-label" for="wpcmb-block-mode">%s</label>', esc_html__( 'Default mode', 'wp-custom-meta-box' ) );
		echo '<select class="widefat" id="wpcmb-block-mode" name="wpcmb[settings][block_mode]">';

		foreach ( array(
			'auto'    => __( 'Edit, then preview', 'wp-custom-meta-box' ),
			'preview' => __( 'Preview', 'wp-custom-meta-box' ),
			'edit'    => __( 'Edit', 'wp-custom-meta-box' ),
		) as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $settings['block_mode'] ?? 'auto', $value, false ),
				esc_html( $label )
			);
		}

		echo '</select></p>';

		$chosen = (array) ( $settings['block_supports'] ?? array() );

		echo '<fieldset class="wpcmb-fieldset"><legend>' . esc_html__( 'Supports', 'wp-custom-meta-box' ) . '</legend>';

		foreach ( array(
			'align'             => __( 'Alignment', 'wp-custom-meta-box' ),
			'anchor'            => __( 'Anchor', 'wp-custom-meta-box' ),
			'custom_class_name' => __( 'Additional CSS class', 'wp-custom-meta-box' ),
			'color'             => __( 'Colour', 'wp-custom-meta-box' ),
			'spacing'           => __( 'Spacing', 'wp-custom-meta-box' ),
			'typography'        => __( 'Typography', 'wp-custom-meta-box' ),
		) as $value => $label ) {
			printf(
				'<label class="wpcmb-checkbox"><input type="checkbox" name="wpcmb[settings][block_supports][]" value="%s"%s /> %s</label>',
				esc_attr( $value ),
				checked( in_array( $value, $chosen, true ), true, false ),
				esc_html( $label )
			);
		}

		printf(
			'<label class="wpcmb-checkbox"><input type="hidden" name="wpcmb[settings][block_inner_blocks]" value="" />
			<input type="checkbox" name="wpcmb[settings][block_inner_blocks]" value="1"%s /> %s</label>',
			checked( ! empty( $settings['block_inner_blocks'] ), true, false ),
			esc_html__( 'Allow inner blocks', 'wp-custom-meta-box' )
		);

		echo '</fieldset>';
	}

	/**
	 * Replace the title placeholder.
	 *
	 * @param string   $text Placeholder text.
	 * @param \WP_Post $post Current post.
	 */
	public function title_placeholder( $text, $post ): string {
		if ( $post instanceof \WP_Post && FieldGroupPostType::POST_TYPE === $post->post_type ) {
			return __( 'Field group title', 'wp-custom-meta-box' );
		}

		return (string) $text;
	}

	/**
	 * Render the fields builder.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_fields( \WP_Post $post ): void {
		$group = $this->group( $post );

		wp_nonce_field( self::NONCE, 'wpcmb_nonce' );

		printf( '<input type="hidden" name="wpcmb[key]" value="%s" />', esc_attr( $group->key ) );
		?>
		<div class="wpcmb-builder" data-wpcmb-builder="fields">
			<textarea
				class="wpcmb-builder__state"
				name="wpcmb_fields_json"
				hidden
				aria-hidden="true"
			><?php echo esc_textarea( (string) wp_json_encode( $group->fields ) ); ?></textarea>

			<div class="wpcmb-builder__list" data-wpcmb-list></div>

			<p class="wpcmb-builder__actions">
				<button type="button" class="wpcmb-btn wpcmb-btn--add" data-wpcmb-add-field>
					<?php esc_html_e( 'Add Field', 'wp-custom-meta-box' ); ?>
				</button>
			</p>

			<p class="wpcmb-builder__fallback">
				<?php esc_html_e( 'The fields builder needs JavaScript. Your saved fields are unchanged while it is unavailable.', 'wp-custom-meta-box' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the location rule builder.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_location( \WP_Post $post ): void {
		$group = $this->group( $post );
		?>
		<div class="wpcmb-builder" data-wpcmb-builder="location">
			<p class="description">
				<?php esc_html_e( 'Show this field group when all rules in any one group match.', 'wp-custom-meta-box' ); ?>
			</p>

			<textarea
				class="wpcmb-builder__state"
				name="wpcmb_location_json"
				hidden
				aria-hidden="true"
			><?php echo esc_textarea( (string) wp_json_encode( $group->location ) ); ?></textarea>

			<div class="wpcmb-builder__list" data-wpcmb-list></div>

			<p class="wpcmb-builder__actions">
				<button type="button" class="wpcmb-btn wpcmb-btn--add" data-wpcmb-add-group>
					<?php esc_html_e( 'Add Rule Group', 'wp-custom-meta-box' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the display settings.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_settings( \WP_Post $post ): void {
		$settings = $this->group( $post )->settings;

		$selects = array(
			'position'        => array(
				'label'   => __( 'Position', 'wp-custom-meta-box' ),
				'choices' => array(
					'normal'   => __( 'After content', 'wp-custom-meta-box' ),
					'side'     => __( 'Side', 'wp-custom-meta-box' ),
					'advanced' => __( 'Advanced', 'wp-custom-meta-box' ),
				),
			),
			'style'           => array(
				'label'   => __( 'Style', 'wp-custom-meta-box' ),
				'choices' => array(
					'default'  => __( 'Standard meta box', 'wp-custom-meta-box' ),
					'seamless' => __( 'Seamless', 'wp-custom-meta-box' ),
				),
			),
			'label_placement' => array(
				'label'   => __( 'Label placement', 'wp-custom-meta-box' ),
				'choices' => array(
					'top'  => __( 'Above fields', 'wp-custom-meta-box' ),
					'left' => __( 'Beside fields', 'wp-custom-meta-box' ),
				),
			),
		);

		echo '<p class="description">' . esc_html__( 'Publish this group to activate it. Saving it as a draft keeps it inactive.', 'wp-custom-meta-box' ) . '</p>';

		foreach ( $selects as $name => $select ) {
			printf( '<p><label class="wpcmb-label" for="wpcmb-%1$s">%2$s</label>', esc_attr( $name ), esc_html( $select['label'] ) );
			printf( '<select class="widefat" id="wpcmb-%1$s" name="wpcmb[settings][%1$s]">', esc_attr( $name ) );

			foreach ( $select['choices'] as $value => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $value ),
					selected( $settings[ $name ] ?? '', $value, false ),
					esc_html( $label )
				);
			}

			echo '</select></p>';
		}

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-menu-order">%s</label>
			<input class="widefat" type="number" id="wpcmb-menu-order" name="wpcmb[settings][menu_order]" value="%s" /></p>',
			esc_html__( 'Order', 'wp-custom-meta-box' ),
			esc_attr( (string) ( $settings['menu_order'] ?? 0 ) )
		);

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-description">%s</label>
			<input class="widefat" type="text" id="wpcmb-description" name="wpcmb[settings][description]" value="%s" /></p>',
			esc_html__( 'Description', 'wp-custom-meta-box' ),
			esc_attr( (string) ( $settings['description'] ?? '' ) )
		);

		$hidden = (array) ( $settings['hide_on_screen'] ?? array() );

		echo '<fieldset class="wpcmb-fieldset"><legend>' . esc_html__( 'Hide on screen', 'wp-custom-meta-box' ) . '</legend>';

		foreach ( $this->hideable_elements() as $value => $label ) {
			printf(
				'<label class="wpcmb-checkbox"><input type="checkbox" name="wpcmb[settings][hide_on_screen][]" value="%s"%s /> %s</label>',
				esc_attr( $value ),
				checked( in_array( $value, $hidden, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</fieldset>';

		/*
		 * Two snippets, because there is no one honest snippet. A form takes
		 * submissions from signed-in visitors only unless it is told
		 * otherwise, and the earlier wording here promised "a public form"
		 * while handing over the shortcode that refuses one — so the first
		 * thing anybody saw after pasting it was "You need to sign in".
		 */
		$key = $this->group( $post )->key;

		printf(
			'<p><label class="wpcmb-label" for="wpcmb-shortcode">%1$s</label>
			<input class="widefat code" type="text" id="wpcmb-shortcode" value="%2$s" readonly onfocus="this.select()" />
			<span class="description">%3$s</span></p>

			<p><label class="wpcmb-label" for="wpcmb-shortcode-guests">%4$s</label>
			<input class="widefat code" type="text" id="wpcmb-shortcode-guests" value="%5$s" readonly onfocus="this.select()" />
			<span class="description">%6$s</span></p>',
			esc_html__( 'Front-end form — signed-in visitors', 'wp-custom-meta-box' ),
			esc_attr( wpcmb_form_shortcode( $key ) ),
			esc_html__( 'Paste into any post or page. Visitors who are not signed in are asked to sign in.', 'wp-custom-meta-box' ),
			esc_html__( 'Front-end form — anyone', 'wp-custom-meta-box' ),
			esc_attr( wpcmb_form_shortcode( $key, array( 'guests' => '1' ) ) ),
			esc_html__( 'Accepts submissions from anyone, signed in or not. Uploads still need an account.', 'wp-custom-meta-box' )
		);
	}

	/**
	 * Persist the editor's input.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ): void {
		$post_id = (int) $post_id;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['wpcmb_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpcmb_nonce'] ) ), self::NONCE )
		) {
			return;
		}

		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			return;
		}

		$raw = isset( $_POST['wpcmb'] ) && is_array( $_POST['wpcmb'] )
			? wp_unslash( $_POST['wpcmb'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by FieldGroup::sanitize().
			: array();

		$raw['title']    = $post instanceof \WP_Post ? $post->post_title : '';
		$raw['fields']   = $this->decode_json_field( 'wpcmb_fields_json' );
		$raw['location'] = $this->decode_json_field( 'wpcmb_location_json' );

		$config = FieldGroup::sanitize( $raw );

		/**
		 * Filters a field group configuration immediately before it is stored.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $config  Sanitized configuration.
		 * @param int                  $post_id Field group post id.
		 */
		$config = (array) apply_filters( 'wpcmb/field_group/pre_save', $config, $post_id );

		$this->container->get( Repository::class )->save( $post_id, $config );

		/**
		 * Fires after a field group has been saved.
		 *
		 * @since 1.0.0
		 *
		 * @param int                  $post_id Field group post id.
		 * @param array<string, mixed> $config  Stored configuration.
		 */
		do_action( 'wpcmb/field_group/saved', $post_id, $config );
	}

	/**
	 * Decode one of the JSON-carrying editor controls.
	 *
	 * Returns an empty array for anything that is not a JSON array, which
	 * FieldGroup::sanitize() then treats as "no fields" or "no rules".
	 *
	 * @param string $key Request key.
	 *
	 * @return array<int, mixed>
	 */
	private function decode_json_field( string $key ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- save() verified the nonce before calling this.
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded, then sanitized by FieldGroup::sanitize().
		$decoded = json_decode( wp_unslash( $_POST[ $key ] ), true );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * The group being edited, or an empty group for a new post.
	 *
	 * @param \WP_Post $post Current post.
	 */
	private function group( \WP_Post $post ): FieldGroup {
		return $this->container->get( Repository::class )->get( $post->ID ) ?? new FieldGroup();
	}

	/**
	 * Editor elements a field group may hide.
	 *
	 * @return array<string, string>
	 */
	private function hideable_elements(): array {
		return array(
			'permalink'       => __( 'Permalink', 'wp-custom-meta-box' ),
			'the_content'     => __( 'Content editor', 'wp-custom-meta-box' ),
			'excerpt'         => __( 'Excerpt', 'wp-custom-meta-box' ),
			'discussion'      => __( 'Discussion', 'wp-custom-meta-box' ),
			'comments'        => __( 'Comments', 'wp-custom-meta-box' ),
			'revisions'       => __( 'Revisions', 'wp-custom-meta-box' ),
			'slug'            => __( 'Slug', 'wp-custom-meta-box' ),
			'author'          => __( 'Author', 'wp-custom-meta-box' ),
			'format'          => __( 'Format', 'wp-custom-meta-box' ),
			'featured_image'  => __( 'Featured image', 'wp-custom-meta-box' ),
			'categories'      => __( 'Categories', 'wp-custom-meta-box' ),
			'tags'            => __( 'Tags', 'wp-custom-meta-box' ),
			'send-trackbacks' => __( 'Send trackbacks', 'wp-custom-meta-box' ),
		);
	}
}
