<?php
/**
 * Field group list screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Locations;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Customises the field group list table.
 *
 * Built on the post type's own list table rather than a `WP_List_Table`
 * subclass: search, pagination, sorting, bulk actions, trash and screen
 * options already work there, so only the columns and the duplicate action
 * are new.
 */
final class FieldGroupList extends Module {

	/**
	 * Query arg carrying the id to duplicate.
	 */
	private const ACTION_DUPLICATE = 'wpcmb_duplicate';

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
		$type = FieldGroupPostType::POST_TYPE;

		add_filter( "manage_{$type}_posts_columns", array( $this, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$type}_sortable_columns", array( $this, 'sortable_columns' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_filter( 'post_date_column_status', array( $this, 'hide_date_status' ), 10, 2 );
		add_action( 'admin_action_' . self::ACTION_DUPLICATE, array( $this, 'handle_duplicate' ) );
		add_action( 'admin_notices', array( $this, 'duplicate_notice' ) );

		// Any write to a field group invalidates the cached group list.
		add_action( 'save_post_' . $type, array( $this, 'flush' ) );
		add_action( 'deleted_post', array( $this, 'flush' ) );
		add_action( 'trashed_post', array( $this, 'flush' ) );
		add_action( 'untrashed_post', array( $this, 'flush' ) );
	}

	/**
	 * Replace the default columns.
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		return array(
			'cb'             => $columns['cb'] ?? '',
			'title'          => __( 'Title', 'wp-custom-meta-box' ),
			'wpcmb_key'      => __( 'Key', 'wp-custom-meta-box' ),
			'wpcmb_fields'   => __( 'Fields', 'wp-custom-meta-box' ),
			'wpcmb_location' => __( 'Location', 'wp-custom-meta-box' ),
			'date'           => __( 'Modified', 'wp-custom-meta-box' ),
		);
	}

	/**
	 * Allow sorting by field count and menu order.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 *
	 * @return array<string, string>
	 */
	public function sortable_columns( $columns ): array {
		$columns              = is_array( $columns ) ? $columns : array();
		$columns['wpcmb_key'] = 'menu_order';

		return $columns;
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post id.
	 */
	public function render_column( $column, $post_id ): void {
		$group = $this->container->get( Repository::class )->get( (int) $post_id );

		if ( ! $group instanceof FieldGroup ) {
			return;
		}

		switch ( $column ) {
			case 'wpcmb_key':
				echo '<code>' . esc_html( $group->key ) . '</code>';
				break;

			case 'wpcmb_fields':
				echo esc_html( (string) count( $group->fields ) );
				break;

			case 'wpcmb_location':
				$summary = Locations::describe( $group->location );
				echo '' !== $summary
					? esc_html( $summary )
					: '<span class="wpcmb-muted">' . esc_html__( 'Not set', 'wp-custom-meta-box' ) . '</span>';
				break;
		}
	}

	/**
	 * Add a Duplicate row action.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Current post.
	 *
	 * @return array<string, string>
	 */
	public function row_actions( $actions, $post ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! $post instanceof \WP_Post || FieldGroupPostType::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			return $actions;
		}

		unset( $actions['inline hide-if-js'] );

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::ACTION_DUPLICATE . '&post=' . $post->ID ),
			self::ACTION_DUPLICATE . '_' . $post->ID
		);

		$actions['wpcmb_duplicate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Duplicate', 'wp-custom-meta-box' )
		);

		return $actions;
	}

	/**
	 * Label inactive groups in the list.
	 *
	 * @param array<string, string> $states Post states.
	 * @param \WP_Post              $post   Current post.
	 *
	 * @return array<string, string>
	 */
	public function post_states( $states, $post ): array {
		$states = is_array( $states ) ? $states : array();

		if ( $post instanceof \WP_Post
			&& FieldGroupPostType::POST_TYPE === $post->post_type
			&& 'draft' === $post->post_status
		) {
			unset( $states['draft'] );
			$states['wpcmb_inactive'] = __( 'Inactive', 'wp-custom-meta-box' );
		}

		return $states;
	}

	/**
	 * Drop the "Last Modified" prefix from the date column.
	 *
	 * @param string   $status Column status text.
	 * @param \WP_Post $post   Current post.
	 */
	public function hide_date_status( $status, $post ): string {
		if ( $post instanceof \WP_Post && FieldGroupPostType::POST_TYPE === $post->post_type ) {
			return '';
		}

		return (string) $status;
	}

	/**
	 * Handle the Duplicate row action.
	 */
	public function handle_duplicate(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		check_admin_referer( self::ACTION_DUPLICATE . '_' . $post_id );

		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate field groups.', 'wp-custom-meta-box' ) );
		}

		$new_id = $this->container->get( Repository::class )->duplicate( $post_id );

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		wp_safe_redirect( add_query_arg( 'wpcmb_duplicated', '1', get_edit_post_link( $new_id, 'raw' ) ) );
		exit;
	}

	/**
	 * Confirm a successful duplication.
	 */
	public function duplicate_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
		if ( empty( $_GET['wpcmb_duplicated'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Field group duplicated. It is inactive until you publish it.', 'wp-custom-meta-box' )
		);
	}

	/**
	 * Invalidate cached field groups.
	 */
	public function flush(): void {
		$this->container->get( Repository::class )->flush();
	}
}
