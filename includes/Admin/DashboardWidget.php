<?php
/**
 * Dashboard widget.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Locations;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Lists field groups on the WordPress dashboard.
 *
 * Registered only for users who can manage field groups, so the widget never
 * appears in another user's screen options.
 */
final class DashboardWidget extends Module {

	/**
	 * Only load on the dashboard, for users who can see field groups.
	 */
	public function is_enabled(): bool {
		return is_admin();
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register' ) );
	}

	/**
	 * Add the widget.
	 */
	public function register(): void {
		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wpcmb_dashboard',
			__( 'Custom Meta Box', 'wp-custom-meta-box' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Render the widget.
	 */
	public function render(): void {
		$groups = $this->container->get( Repository::class )->all( true );

		if ( array() === $groups ) {
			printf(
				'<p>%s</p><p><a class="wpcmb-btn wpcmb-btn--primary" href="%s">%s</a></p>',
				esc_html__( 'No field groups yet.', 'wp-custom-meta-box' ),
				esc_url( admin_url( 'post-new.php?post_type=' . FieldGroupPostType::POST_TYPE ) ),
				esc_html__( 'Add Field Group', 'wp-custom-meta-box' )
			);

			return;
		}

		echo '<ul class="wpcmb-dashboard-list">';

		foreach ( array_slice( $groups, 0, 8 ) as $group ) {
			$summary = Locations::describe( $group->location );

			printf(
				'<li><a href="%s">%s</a> <span class="wpcmb-muted">%s</span>%s</li>',
				esc_url( (string) get_edit_post_link( $group->id ) ),
				esc_html( '' !== $group->title ? $group->title : $group->key ),
				esc_html( '' !== $summary ? $summary : __( 'No location set', 'wp-custom-meta-box' ) ),
				$group->is_active() ? '' : ' <em>' . esc_html__( '(inactive)', 'wp-custom-meta-box' ) . '</em>'
			);
		}

		echo '</ul>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'edit.php?post_type=' . FieldGroupPostType::POST_TYPE ) ),
			esc_html__( 'Manage field groups', 'wp-custom-meta-box' )
		);
	}
}
