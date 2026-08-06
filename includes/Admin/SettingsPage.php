<?php
/**
 * Settings screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
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
			<form action="options.php" method="post">
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
