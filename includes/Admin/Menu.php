<?php
/**
 * Admin menu and overview screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level menu and renders the overview screen.
 *
 * The field group post type hangs its own submenus off this menu via
 * `show_in_menu`, so only the pages that are not post-type screens are
 * registered here.
 */
final class Menu extends Module {

	/**
	 * Top-level menu slug. Also the overview screen's page slug.
	 */
	public const SLUG = 'wpcmb';

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
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_parent' ) );
	}

	/**
	 * Register the menu and its non-post-type pages.
	 */
	public function register(): void {
		$capability = FieldGroupPostType::capability();

		add_menu_page(
			__( 'Custom Meta Box', 'wp-custom-meta-box' ),
			__( 'Meta Boxes', 'wp-custom-meta-box' ),
			$capability,
			self::SLUG,
			array( $this, 'render_overview' ),
			'dashicons-feedback',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'Overview', 'wp-custom-meta-box' ),
			__( 'Overview', 'wp-custom-meta-box' ),
			$capability,
			self::SLUG,
			array( $this, 'render_overview' )
		);
	}

	/**
	 * Keep the Overview submenu unhighlighted while editing a field group.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 *
	 * @return string|null
	 */
	public function highlight_parent( $submenu_file ) {
		$screen = get_current_screen();

		if ( $screen instanceof \WP_Screen && FieldGroupPostType::POST_TYPE === $screen->post_type ) {
			return 'edit.php?post_type=' . FieldGroupPostType::POST_TYPE;
		}

		return $submenu_file;
	}

	/**
	 * Render the overview screen.
	 */
	public function render_overview(): void {
		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-custom-meta-box' ) );
		}

		$repository = $this->container->get( Repository::class );
		$all        = $repository->all( true );
		$active     = $repository->all();

		$cards = array(
			array(
				'title' => __( 'Create a field group', 'wp-custom-meta-box' ),
				'text'  => __( 'Define the fields you need, then choose the screens they appear on.', 'wp-custom-meta-box' ),
				'url'   => admin_url( 'post-new.php?post_type=' . FieldGroupPostType::POST_TYPE ),
				'label' => __( 'Add Field Group', 'wp-custom-meta-box' ),
			),
			array(
				'title' => __( 'Import and export', 'wp-custom-meta-box' ),
				'text'  => __( 'Move field groups between sites as JSON, or export them as PHP for version control.', 'wp-custom-meta-box' ),
				'url'   => admin_url( 'admin.php?page=' . ToolsPage::SLUG ),
				'label' => __( 'Open Tools', 'wp-custom-meta-box' ),
			),
			array(
				'title' => __( 'Settings', 'wp-custom-meta-box' ),
				'text'  => __( 'Control the admin theme and what happens to your data when the plugin is deleted.', 'wp-custom-meta-box' ),
				'url'   => admin_url( 'admin.php?page=' . SettingsPage::SLUG ),
				'label' => __( 'Open Settings', 'wp-custom-meta-box' ),
			),
		);

		$inactive = count( $all ) - count( $active );
		?>
		<div class="wrap wpcmb-wrap">
			<h1><?php esc_html_e( 'Custom Meta Box', 'wp-custom-meta-box' ); ?></h1>

			<?php if ( array() === $all ) : ?>
				<div class="wpcmb-empty wpcmb-empty--page">
					<span class="wpcmb-empty__title">
						<?php esc_html_e( 'No field groups yet', 'wp-custom-meta-box' ); ?>
					</span>
					<p>
						<?php esc_html_e( 'A field group is a set of fields plus the rules for where they appear. Create one to start adding fields to your content.', 'wp-custom-meta-box' ); ?>
					</p>
					<p>
						<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . FieldGroupPostType::POST_TYPE ) ); ?>">
							<?php esc_html_e( 'Create your first field group', 'wp-custom-meta-box' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( 0 !== $inactive ) : ?>
				<p class="wpcmb-hint">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of inactive field groups. */
							_n(
								'%d field group is saved as a draft, so its fields are not showing yet.',
								'%d field groups are saved as drafts, so their fields are not showing yet.',
								$inactive,
								'wp-custom-meta-box'
							),
							$inactive
						)
					);
					?>
				</p>
			<?php endif; ?>

			<div class="wpcmb-stats">
				<div class="wpcmb-stat">
					<span class="wpcmb-stat__value"><?php echo esc_html( (string) count( $all ) ); ?></span>
					<span class="wpcmb-stat__label"><?php esc_html_e( 'Field groups', 'wp-custom-meta-box' ); ?></span>
				</div>
				<div class="wpcmb-stat">
					<span class="wpcmb-stat__value"><?php echo esc_html( (string) count( $active ) ); ?></span>
					<span class="wpcmb-stat__label"><?php esc_html_e( 'Active', 'wp-custom-meta-box' ); ?></span>
				</div>
				<div class="wpcmb-stat">
					<span class="wpcmb-stat__value"><?php echo esc_html( (string) array_sum( array_map( static fn( $g ): int => count( $g->fields ), $all ) ) ); ?></span>
					<span class="wpcmb-stat__label"><?php esc_html_e( 'Fields', 'wp-custom-meta-box' ); ?></span>
				</div>
			</div>

			<div class="wpcmb-cards">
				<?php foreach ( $cards as $card ) : ?>
					<div class="wpcmb-card">
						<h2><?php echo esc_html( $card['title'] ); ?></h2>
						<p><?php echo esc_html( $card['text'] ); ?></p>
						<a class="button button-primary" href="<?php echo esc_url( $card['url'] ); ?>">
							<?php echo esc_html( $card['label'] ); ?>
						</a>
					</div>
				<?php endforeach; ?>
			</div>

			<?php
			/**
			 * Fires at the end of the overview screen.
			 *
			 * @since 1.0.0
			 */
			do_action( 'wpcmb/admin/overview' );
			?>
		</div>
		<?php
	}
}
