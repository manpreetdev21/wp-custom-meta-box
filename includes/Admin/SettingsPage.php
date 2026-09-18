<?php
/**
 * Settings screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\FieldTypes\Choice;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings, built on the WordPress Settings API.
 *
 * The Settings API supplies the nonce, the capability check, the option
 * writes and the "Settings saved" notice, so this class only declares the
 * fields and their sanitizers.
 */
final class SettingsPage extends Module {

	/**
	 * Page slug.
	 */
	public const SLUG = 'wpcmb-settings';

	/**
	 * Option group name.
	 */
	private const OPTION_GROUP = 'wpcmb_settings';

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
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the settings submenu.
	 */
	public function register_page(): void {
		add_submenu_page(
			Menu::SLUG,
			__( 'Settings', 'wp-custom-meta-box' ),
			__( 'Settings', 'wp-custom-meta-box' ),
			FieldGroupPostType::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Declare settings, sections and fields.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			'wpcmb_admin_theme',
			array(
				'type'              => 'string',
				'default'           => 'auto',
				'sanitize_callback' => static fn( $value ): string =>
					in_array( $value, array( 'auto', 'light', 'dark' ), true ) ? (string) $value : 'auto',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'wpcmb_delete_data_on_uninstall',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => static fn( $value ): bool => (bool) $value,
			)
		);

		add_settings_section(
			'wpcmb_general',
			__( 'General', 'wp-custom-meta-box' ),
			'__return_false',
			self::SLUG
		);

		add_settings_field(
			'wpcmb_admin_theme',
			__( 'Admin theme', 'wp-custom-meta-box' ),
			array( $this, 'render_theme_field' ),
			self::SLUG,
			'wpcmb_general',
			array( 'label_for' => 'wpcmb_admin_theme' )
		);

		add_settings_field(
			'wpcmb_delete_data_on_uninstall',
			__( 'On uninstall', 'wp-custom-meta-box' ),
			array( $this, 'render_uninstall_field' ),
			self::SLUG,
			'wpcmb_general'
		);

		$this->register_list_settings();
	}

	/**
	 * Declare the editable country and region lists.
	 *
	 * Kept as free text in the same `value : Label` form the choices setting
	 * uses rather than a row-per-entry builder: these lists are pasted and
	 * bulk-edited far more often than they are picked at one by one, and a
	 * textarea is the control that makes that easy.
	 */
	private function register_list_settings(): void {
		foreach ( array( Choice::COUNTRIES_OPTION, Choice::STATES_OPTION ) as $option ) {
			register_setting(
				self::OPTION_GROUP,
				$option,
				array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => array( $this, 'sanitize_list' ),
				)
			);
		}

		add_settings_section(
			'wpcmb_lists',
			__( 'Country and region lists', 'wp-custom-meta-box' ),
			array( $this, 'render_lists_intro' ),
			self::SLUG
		);

		add_settings_field(
			Choice::COUNTRIES_OPTION,
			__( 'Countries', 'wp-custom-meta-box' ),
			array( $this, 'render_countries_field' ),
			self::SLUG,
			'wpcmb_lists',
			array( 'label_for' => Choice::COUNTRIES_OPTION )
		);

		add_settings_field(
			Choice::STATES_OPTION,
			__( 'States / regions', 'wp-custom-meta-box' ),
			array( $this, 'render_states_field' ),
			self::SLUG,
			'wpcmb_lists',
			array( 'label_for' => Choice::STATES_OPTION )
		);
	}

	/**
	 * Clean a submitted list.
	 *
	 * Line breaks have to survive, so this is not sanitize_textarea_field:
	 * each line is cleaned on its own and the structure is rebuilt.
	 *
	 * @param mixed $value Submitted value.
	 */
	public function sanitize_list( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$lines = array_filter(
			array_map(
				static fn( $line ): string => trim( sanitize_text_field( $line ) ),
				(array) preg_split( '/\r\n|\r|\n/', $value )
			),
			static fn( $line ): bool => '' !== $line
		);

		return implode( "\n", $lines );
	}

	/**
	 * Explain what the two lists do.
	 */
	public function render_lists_intro(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'One entry per line, as "value : Label" — for example "FR : France". The value is what gets stored; the label is what editors see. Leave a list empty to use the built-in one.',
				'wp-custom-meta-box'
			)
		);
	}

	/**
	 * Render the countries list control.
	 */
	public function render_countries_field(): void {
		$this->render_list_field(
			Choice::COUNTRIES_OPTION,
			"FR : France\nDE : Germany",
			__( 'Adds to or replaces the built-in ISO country list.', 'wp-custom-meta-box' )
		);
	}

	/**
	 * Render the regions list control.
	 */
	public function render_states_field(): void {
		$this->render_list_field(
			Choice::STATES_OPTION,
			"NSW : New South Wales\nVIC : Victoria",
			__( 'The built-in list is US states. Replace it with the regions your site actually uses.', 'wp-custom-meta-box' )
		);
	}

	/**
	 * Render one list textarea.
	 *
	 * @param string $option      Option name.
	 * @param string $placeholder Example content.
	 * @param string $help        Description below the control.
	 */
	private function render_list_field( string $option, string $placeholder, string $help ): void {
		printf(
			'<textarea id="%1$s" name="%1$s" class="large-text code" rows="8" placeholder="%2$s">%3$s</textarea>
			<p class="description">%4$s</p>',
			esc_attr( $option ),
			esc_attr( $placeholder ),
			esc_textarea( (string) get_option( $option, '' ) ),
			esc_html( $help )
		);
	}

	/**
	 * Render the admin theme control.
	 */
	public function render_theme_field(): void {
		$current = (string) get_option( 'wpcmb_admin_theme', 'auto' );

		$choices = array(
			'auto'  => __( 'Follow the operating system', 'wp-custom-meta-box' ),
			'light' => __( 'Always light', 'wp-custom-meta-box' ),
			'dark'  => __( 'Always dark', 'wp-custom-meta-box' ),
		);

		echo '<select id="wpcmb_admin_theme" name="wpcmb_admin_theme">';

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Render the uninstall behaviour control.
	 */
	public function render_uninstall_field(): void {
		printf(
			'<label><input type="checkbox" name="wpcmb_delete_data_on_uninstall" value="1"%s /> %s</label>
			<p class="description">%s</p>',
			checked( (bool) get_option( 'wpcmb_delete_data_on_uninstall' ), true, false ),
			esc_html__( 'Delete all plugin data when the plugin is deleted', 'wp-custom-meta-box' ),
			esc_html__( 'Off by default. Deleting a plugin to reinstall it should not cost you your field groups.', 'wp-custom-meta-box' )
		);
	}

	/**
	 * Render the settings screen.
	 */
	public function render(): void {
		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-custom-meta-box' ) );
		}

		?>
		<div class="wrap wpcmb-wrap">
			<h1><?php esc_html_e( 'Custom Meta Box Settings', 'wp-custom-meta-box' ); ?></h1>
			<p class="wpcmb-lede"><?php esc_html_e( 'How the plugin looks while you work, and what it leaves behind when it goes.', 'wp-custom-meta-box' ); ?></p>
			<form action="options.php" method="post" class="wpcmb-settings-form">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
