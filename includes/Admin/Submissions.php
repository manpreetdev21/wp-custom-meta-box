<?php
/**
 * Reading stored form submissions.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Values;
use WPCMB\PostTypes\SubmissionPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Shows what a submission contains.
 *
 * Storing submissions is only half of it: the values land in post meta, and
 * post meta is invisible. The fields cannot be rendered the usual way either,
 * because the group's location rules point at wherever its form is, not at
 * this screen — and even if they matched, editable controls would offer a
 * save that no location rule would honour.
 *
 * So this reads instead of edits: the recorded group says which fields the
 * submission has, and each is shown formatted, in the order the form asked
 * for them.
 */
final class Submissions extends Module {

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
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );

		foreach ( array_keys( SubmissionPostType::recorded() ) as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'column' ), 10, 2 );
		}
	}

	/**
	 * Add the read-only panel to a submission screen.
	 *
	 * @param string   $post_type Current post type.
	 * @param \WP_Post $post      Current post.
	 */
	public function add_meta_box( $post_type, $post = null ): void {
		if ( ! is_string( $post_type ) || ! SubmissionPostType::is_submission_type( $post_type ) ) {
			return;
		}

		add_meta_box(
			'wpcmb-submission',
			__( 'Submitted values', 'wp-custom-meta-box' ),
			function ( $post ): void {
				$this->render( $post instanceof \WP_Post ? $post : null );
			},
			$post_type,
			'normal',
			'high'
		);
	}

	/**
	 * Render one submission's values.
	 *
	 * @param \WP_Post|null $post Submission.
	 */
	private function render( ?\WP_Post $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$group = $this->group_of( $post );

		if ( ! $group instanceof FieldGroup ) {
			printf(
				'<p class="wpcmb-submission__empty">%s</p>',
				esc_html__( 'The field group this came from no longer exists, so its values cannot be labelled.', 'wp-custom-meta-box' )
			);

			return;
		}

		$renderer = $this->container->get( Renderer::class );
		$values   = $this->container->get( Values::class );
		$ref      = new ObjectRef( ObjectRef::POST, (int) $post->ID );
		$rows     = '';

		foreach ( $group->fields as $field ) {
			$name = (string) ( $field['name'] ?? '' );

			if ( '' === $name || ! $renderer->stores_value( $field ) ) {
				continue;
			}

			$rows .= sprintf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( '' !== (string) ( $field['label'] ?? '' ) ? (string) $field['label'] : $name ),
				$this->display( $values->get( $name, $ref ) )
			);
		}

		if ( '' === $rows ) {
			printf(
				'<p class="wpcmb-submission__empty">%s</p>',
				esc_html__( 'This submission is empty.', 'wp-custom-meta-box' )
			);

			return;
		}

		/*
		 * Core's own table class. No stylesheet of this plugin's loads on a
		 * submission screen — the field assets load where a group matches the
		 * screen, and no group matches this one — so borrowing the layout
		 * wp-admin already has is better than shipping a stylesheet to style
		 * one table.
		 */
		printf(
			'<table class="form-table wpcmb-submission" role="presentation">%s</table>',
			$rows // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		);

		$source = (string) get_post_meta( $post->ID, SubmissionPostType::META_SOURCE, true );

		if ( '' !== $source ) {
			printf(
				'<p class="wpcmb-submission__source">%s <a href="%s">%s</a></p>',
				esc_html__( 'Submitted from', 'wp-custom-meta-box' ),
				esc_url( $source ),
				esc_html( $source )
			);
		}
	}

	/**
	 * One value, as something readable.
	 *
	 * Repeater rows and groups are nested lists rather than JSON: a person
	 * reading a submission wants the answers, not the shape they were stored
	 * in.
	 *
	 * @param mixed $value Stored value.
	 */
	private function display( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return esc_html( $value ? __( 'Yes', 'wp-custom-meta-box' ) : __( 'No', 'wp-custom-meta-box' ) );
		}

		if ( is_scalar( $value ) ) {
			$text = (string) $value;

			return '' === $text
				? '<span class="wpcmb-submission__blank">&mdash;</span>'
				: nl2br( esc_html( $text ) );
		}

		if ( ! is_array( $value ) || array() === $value ) {
			return '<span class="wpcmb-submission__blank">&mdash;</span>';
		}

		$items = '';

		foreach ( $value as $key => $item ) {
			$label = is_string( $key ) ? esc_html( $key ) . ': ' : '';

			$items .= sprintf( '<li>%s%s</li>', $label, $this->display( $item ) );
		}

		return sprintf( '<ul class="wpcmb-submission__list">%s</ul>', $items );
	}

	/**
	 * The group a submission came from.
	 *
	 * @param \WP_Post $post Submission.
	 */
	private function group_of( \WP_Post $post ): ?FieldGroup {
		$key = (string) get_post_meta( $post->ID, SubmissionPostType::META_GROUP, true );

		if ( '' === $key ) {
			return null;
		}

		return $this->container->get( Repository::class )->get( $key );
	}

	/**
	 * Replace the columns a submission list shows.
	 *
	 * The author column goes: a submission's author is whoever was signed in,
	 * which for a public form is nobody. The date stays, because when a
	 * submission arrived is the thing most often being looked for.
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string>
	 */
	public function columns( $columns ): array {
		$columns = is_array( $columns ) ? $columns : array();

		unset( $columns['author'] );

		$date = $columns['date'] ?? __( 'Date', 'wp-custom-meta-box' );

		unset( $columns['date'] );

		$columns['wpcmb_from'] = __( 'From', 'wp-custom-meta-box' );
		$columns['date']       = $date;

		return $columns;
	}

	/**
	 * Fill one of our columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Submission id.
	 */
	public function column( $column, $post_id ): void {
		if ( 'wpcmb_from' !== $column ) {
			return;
		}

		$post = get_post( (int) $post_id );
		$user = $post instanceof \WP_Post ? (int) $post->post_author : 0;

		if ( 0 !== $user ) {
			$author = get_userdata( $user );

			echo esc_html( $author instanceof \WP_User ? $author->display_name : (string) $user );

			return;
		}

		echo esc_html__( 'A visitor', 'wp-custom-meta-box' );
	}
}
