<?php
/**
 * The field group list, on a screen the plugin owns.
 *
 * WordPress already provides a list at edit.php for the post type, and it is
 * still there — bulk actions, trash and the rest live on it. This screen
 * exists because that one is core markup end to end, so the only way to give
 * it a header bar or anything else of ours was to paint core's list table,
 * which would change how a list table looks here versus everywhere else in
 * WordPress.
 *
 * Everything rendered below is this plugin's own markup with this plugin's own
 * classes, so it can be styled freely without touching anything core owns.
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
 * Renders the field group list screen.
 */
final class GroupsPage extends Module {

	/**
	 * Page slug.
	 */
	public const SLUG = 'wpcmb-groups';

	/**
	 * Admin only.
	 */
	public function is_enabled(): bool {
		return is_admin();
	}

	/**
	 * Register hooks.
	 *
	 * Priority 11 so the parent menu Menu::register() adds at the default
	 * priority already exists to hang this off.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register' ), 11 );
	}

	/**
	 * Register the submenu page.
	 */
	public function register(): void {
		add_submenu_page(
			Menu::SLUG,
			__( 'Field Groups', 'wp-custom-meta-box' ),
			__( 'Field Groups', 'wp-custom-meta-box' ),
			FieldGroupPostType::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-custom-meta-box' ) );
		}

		$groups  = $this->container->get( Repository::class )->all( true );
		$search  = $this->request( 's' );
		$status  = $this->request( 'status' );
		$status  = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : 'all';
		$visible = $this->filter( $groups, $search, $status );

		echo '<div class="wrap wpcmb-wrap">';

		$this->render_header( $groups );
		$this->render_toolbar( $groups, $search, $status, count( $visible ) );

		if ( array() === $visible ) {
			$this->render_empty( array() === $groups, $search );
		} else {
			$this->render_table( $visible );
		}

		echo '</div>';
	}

	/**
	 * A trimmed query parameter.
	 *
	 * Read for display and filtering only — nothing here changes state, which
	 * is why there is no nonce on the way in.
	 *
	 * @param string $key Parameter name.
	 */
	private function request( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		return isset( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) : '';
	}

	/**
	 * Narrow the list to what the current filters ask for.
	 *
	 * Filtered in PHP over the whole set, which is already loaded and cached.
	 *
	 * ponytail: no paging. Field groups number in the tens, not the thousands.
	 * Move to a WP_Query with paging if a site ever proves otherwise.
	 *
	 * @param array<int, FieldGroup> $groups All groups.
	 * @param string                 $search Search term.
	 * @param string                 $status all, active or inactive.
	 *
	 * @return array<int, FieldGroup>
	 */
	private function filter( array $groups, string $search, string $status ): array {
		$needle = strtolower( $search );

		return array_values(
			array_filter(
				$groups,
				static function ( FieldGroup $group ) use ( $needle, $status ): bool {
					if ( 'active' === $status && ! $group->is_active() ) {
						return false;
					}

					if ( 'inactive' === $status && $group->is_active() ) {
						return false;
					}

					if ( '' === $needle ) {
						return true;
					}

					// Key as well as title: a developer looking for a group
					// usually has the key in front of them, not the label.
					return str_contains( strtolower( $group->title ), $needle )
						|| str_contains( strtolower( $group->key ), $needle );
				}
			)
		);
	}

	/**
	 * The header bar: what this screen is, and the one action worth promoting.
	 *
	 * @param array<int, FieldGroup> $groups All groups.
	 */
	private function render_header( array $groups ): void {
		$active = 0;
		$fields = 0;

		foreach ( $groups as $group ) {
			$active += $group->is_active() ? 1 : 0;
			$fields += count( $group->fields );
		}

		printf(
			'<div class="wpcmb-page-header">
				<div class="wpcmb-page-header__text">
					<h1 class="wpcmb-page-header__title">%1$s</h1>
					<p class="wpcmb-page-header__sub">%2$s</p>
				</div>
				<div class="wpcmb-page-header__actions">
					<a class="wpcmb-btn wpcmb-btn--primary" href="%3$s">%4$s</a>
				</div>
			</div>',
			esc_html__( 'Field Groups', 'wp-custom-meta-box' ),
			esc_html__( 'Each group decides what a piece of content collects, and where it appears.', 'wp-custom-meta-box' ),
			esc_url( admin_url( 'post-new.php?post_type=' . FieldGroupPostType::POST_TYPE ) ),
			esc_html__( 'New field group', 'wp-custom-meta-box' )
		);

		printf(
			'<div class="wpcmb-page-stats">
				<span class="wpcmb-page-stat"><strong>%1$d</strong> %2$s</span>
				<span class="wpcmb-page-stat"><strong>%3$d</strong> %4$s</span>
				<span class="wpcmb-page-stat"><strong>%5$d</strong> %6$s</span>
			</div>',
			count( $groups ),
			esc_html( _n( 'group', 'groups', count( $groups ), 'wp-custom-meta-box' ) ),
			absint( $active ),
			esc_html__( 'active', 'wp-custom-meta-box' ),
			absint( $fields ),
			esc_html( _n( 'field', 'fields', $fields, 'wp-custom-meta-box' ) )
		);
	}

	/**
	 * Status filter and search.
	 *
	 * @param array<int, FieldGroup> $groups  All groups.
	 * @param string                 $search  Current search term.
	 * @param string                 $status  Current status filter.
	 * @param int                    $showing How many rows the filters leave.
	 */
	private function render_toolbar( array $groups, string $search, string $status, int $showing ): void {
		$counts = array(
			'all'      => count( $groups ),
			'active'   => 0,
			'inactive' => 0,
		);

		foreach ( $groups as $group ) {
			++$counts[ $group->is_active() ? 'active' : 'inactive' ];
		}

		$labels = array(
			'all'      => __( 'All', 'wp-custom-meta-box' ),
			'active'   => __( 'Active', 'wp-custom-meta-box' ),
			'inactive' => __( 'Inactive', 'wp-custom-meta-box' ),
		);

		echo '<div class="wpcmb-listbar">';
		echo '<div class="wpcmb-listbar__filters" role="group">';

		foreach ( $labels as $value => $label ) {
			$url = add_query_arg(
				array(
					'page'   => self::SLUG,
					'status' => 'all' === $value ? false : $value,
					's'      => '' !== $search ? $search : false,
				),
				admin_url( 'admin.php' )
			);

			printf(
				'<a class="wpcmb-listbar__filter%1$s" href="%2$s"%3$s>%4$s<span class="wpcmb-count">%5$d</span></a>',
				$status === $value ? ' is-active' : '',
				esc_url( $url ),
				$status === $value ? ' aria-current="page"' : '',
				esc_html( $label ),
				(int) $counts[ $value ]
			);
		}

		echo '</div>';

		printf(
			'<form class="wpcmb-listbar__search" method="get" action="%1$s">
				<input type="hidden" name="page" value="%2$s" />
				<input type="hidden" name="status" value="%3$s" />
				<label class="screen-reader-text" for="wpcmb-search">%4$s</label>
				<input type="search" id="wpcmb-search" class="wpcmb-field-row__control" name="s" value="%5$s" placeholder="%6$s" />
				<button type="submit" class="wpcmb-btn wpcmb-btn--sm">%7$s</button>
			</form>',
			esc_url( admin_url( 'admin.php' ) ),
			esc_attr( self::SLUG ),
			esc_attr( $status ),
			esc_html__( 'Search field groups', 'wp-custom-meta-box' ),
			esc_attr( $search ),
			esc_attr__( 'Search title or key…', 'wp-custom-meta-box' ),
			esc_html__( 'Search', 'wp-custom-meta-box' )
		);

		echo '</div>';

		// Only worth saying when a filter is actually hiding something.
		if ( '' !== $search || 'all' !== $status ) {
			printf(
				'<p class="wpcmb-listbar__result">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: matching groups, 2: total groups. */
						_n( '%1$d of %2$d group', '%1$d of %2$d groups', count( $groups ), 'wp-custom-meta-box' ),
						$showing,
						count( $groups )
					)
				)
			);
		}
	}

	/**
	 * The list itself.
	 *
	 * A real table, because this is tabular: five facts about each of many
	 * rows, compared down columns. A grid of cards would look more modern and
	 * be harder to scan.
	 *
	 * @param array<int, FieldGroup> $groups Groups to show.
	 */
	private function render_table( array $groups ): void {
		echo '<table class="wpcmb-table">';

		printf(
			'<thead><tr>
				<th scope="col" class="wpcmb-table__col--title">%1$s</th>
				<th scope="col" class="wpcmb-table__col--fields">%2$s</th>
				<th scope="col" class="wpcmb-table__col--location">%3$s</th>
				<th scope="col" class="wpcmb-table__col--status">%4$s</th>
				<th scope="col" class="wpcmb-table__col--actions"><span class="screen-reader-text">%5$s</span></th>
			</tr></thead>',
			esc_html__( 'Field group', 'wp-custom-meta-box' ),
			esc_html__( 'Fields', 'wp-custom-meta-box' ),
			esc_html__( 'Location', 'wp-custom-meta-box' ),
			esc_html__( 'Status', 'wp-custom-meta-box' ),
			esc_html__( 'Actions', 'wp-custom-meta-box' )
		);

		echo '<tbody>';

		foreach ( $groups as $group ) {
			$this->render_row( $group );
		}

		echo '</tbody></table>';
	}

	/**
	 * One row.
	 *
	 * @param FieldGroup $group Group to render.
	 */
	private function render_row( FieldGroup $group ): void {
		$edit    = get_edit_post_link( $group->id );
		$count   = count( $group->fields );
		$summary = Locations::describe( $group->location );

		$duplicate = wp_nonce_url(
			admin_url( 'admin.php?action=' . FieldGroupList::ACTION_DUPLICATE . '&post=' . (int) $group->id ),
			FieldGroupList::ACTION_DUPLICATE . '_' . (int) $group->id
		);

		echo '<tr class="wpcmb-table__row">';

		printf(
			'<td class="wpcmb-table__cell">
				<a class="wpcmb-table__title" href="%1$s">%2$s</a>
				<code class="wpcmb-cell-key">%3$s</code>
			</td>',
			esc_url( (string) $edit ),
			esc_html( '' !== $group->title ? $group->title : __( '(no title)', 'wp-custom-meta-box' ) ),
			esc_html( $group->key )
		);

		printf(
			'<td class="wpcmb-table__cell"><span class="wpcmb-cell-count%1$s">%2$s</span></td>',
			0 === $count ? ' is-empty' : '',
			esc_html( 0 === $count ? __( 'None', 'wp-custom-meta-box' ) : (string) $count )
		);

		printf(
			'<td class="wpcmb-table__cell"><span class="wpcmb-cell-location%1$s">%2$s</span></td>',
			'' === $summary ? ' is-unset' : '',
			esc_html( '' !== $summary ? $summary : __( 'Not set', 'wp-custom-meta-box' ) )
		);

		printf(
			'<td class="wpcmb-table__cell"><span class="wpcmb-status wpcmb-status--%1$s">%2$s</span></td>',
			$group->is_active() ? 'active' : 'inactive',
			esc_html( $group->is_active() ? __( 'Active', 'wp-custom-meta-box' ) : __( 'Inactive', 'wp-custom-meta-box' ) )
		);

		printf(
			'<td class="wpcmb-table__cell wpcmb-table__cell--actions">
				<a class="wpcmb-btn wpcmb-btn--ghost wpcmb-btn--sm" href="%1$s">%2$s</a>
				<a class="wpcmb-btn wpcmb-btn--ghost wpcmb-btn--sm" href="%3$s">%4$s</a>
			</td>',
			esc_url( (string) $edit ),
			esc_html__( 'Edit', 'wp-custom-meta-box' ),
			esc_url( $duplicate ),
			esc_html__( 'Duplicate', 'wp-custom-meta-box' )
		);

		echo '</tr>';
	}

	/**
	 * Nothing to show, for one of two quite different reasons.
	 *
	 * @param bool   $none   Whether there are no groups at all.
	 * @param string $search Current search term.
	 */
	private function render_empty( bool $none, string $search ): void {
		if ( $none ) {
			printf(
				'<div class="wpcmb-empty wpcmb-empty--page">
					<span class="wpcmb-empty__title">%1$s</span>
					<p>%2$s</p>
					<a class="wpcmb-btn wpcmb-btn--primary wpcmb-btn--lg" href="%3$s">%4$s</a>
				</div>',
				esc_html__( 'No field groups yet', 'wp-custom-meta-box' ),
				esc_html__( 'A field group decides what a post, page, term or user collects beyond the editor, and where those fields appear.', 'wp-custom-meta-box' ),
				esc_url( admin_url( 'post-new.php?post_type=' . FieldGroupPostType::POST_TYPE ) ),
				esc_html__( 'Create your first field group', 'wp-custom-meta-box' )
			);

			return;
		}

		printf(
			'<div class="wpcmb-empty wpcmb-empty--page">
				<span class="wpcmb-empty__title">%1$s</span>
				<p>%2$s</p>
				<a class="wpcmb-btn" href="%3$s">%4$s</a>
			</div>',
			esc_html__( 'Nothing matched', 'wp-custom-meta-box' ),
			'' !== $search
				? esc_html(
					sprintf(
						/* translators: %s: the search term. */
						__( 'No field group has %s in its title or key.', 'wp-custom-meta-box' ),
						'"' . $search . '"'
					)
				)
				: esc_html__( 'No field group has that status.', 'wp-custom-meta-box' ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Clear filters', 'wp-custom-meta-box' )
		);
	}
}
