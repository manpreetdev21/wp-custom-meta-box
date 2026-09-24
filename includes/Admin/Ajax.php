<?php
/**
 * Admin AJAX endpoints.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Blocks\BlockRegistry;
use WPCMB\Fields\Context;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Locations;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Permissions;
use WPCMB\Fields\Registry;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Resolver;
use WPCMB\Fields\Validator;
use WPCMB\Frontend\Form;
use WPCMB\PostTypes\FieldGroupPostType;
use WPCMB\FieldTypes\Flexible;
use WPCMB\FieldTypes\Repeater;

defined( 'ABSPATH' ) || exit;

/**
 * Serves repeater rows and CSV round-trips.
 *
 * Every endpoint resolves the field it is working on from a field key rather
 * than trusting a field definition from the request. Keys are unique across
 * the site, so a key is enough to look up the real definition — which means a
 * crafted request cannot invent sub fields, widen a maximum or change a type.
 */
final class Ajax extends Module {

	/**
	 * Nonce action shared by every endpoint here.
	 */
	public const NONCE = 'wpcmb_ajax';

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
		add_action( 'wp_ajax_wpcmb_repeater_row', array( $this, 'repeater_row' ) );
		add_action( 'wp_ajax_nopriv_wpcmb_repeater_row', array( $this, 'repeater_row' ) );
		add_action( 'wp_ajax_wpcmb_repeater_csv_export', array( $this, 'csv_export' ) );
		add_action( 'wp_ajax_wpcmb_repeater_csv_import', array( $this, 'csv_import' ) );
		add_action( 'wp_ajax_wpcmb_block_form', array( $this, 'block_form' ) );
		add_action( 'wp_ajax_wpcmb_location_search', array( $this, 'location_search' ) );
		add_action( 'wp_ajax_wpcmb_embed_preview', array( $this, 'embed_preview' ) );
		add_action( 'wp_ajax_wpcmb_validate_values', array( $this, 'validate_values' ) );
	}

	/**
	 * Search the objects a location rule can point at.
	 *
	 * Searched on demand rather than listed up front: a site can hold tens of
	 * thousands of posts, and shipping them all to the rule builder would
	 * make the editor screen enormous for everyone to serve a rule most
	 * groups never use.
	 */
	public function location_search(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to edit field groups.', 'wp-custom-meta-box' ) ),
				403
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$param  = isset( $_POST['param'] ) ? sanitize_key( wp_unslash( $_POST['param'] ) ) : '';
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$value  = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! isset( Locations::object_params()[ $param ] ) ) {
			wp_send_json_error( array( 'message' => __( 'That rule is not searchable.', 'wp-custom-meta-box' ) ), 400 );
		}

		$results = Locations::search( $param, $search );

		// A rule already pointing at something outside the first page of
		// results must keep it. Without this, opening a group whose rule
		// names post 4312 would show a list that does not contain it, and
		// saving would quietly repoint the rule at whatever came first.
		if ( '' !== $value && ! in_array( $value, array_column( $results, 'value' ), true ) ) {
			array_unshift(
				$results,
				array(
					'value' => $value,
					'label' => Locations::label_for( $param, $value ),
				)
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Render a block's fields as a form, for the block editor.
	 *
	 * The editor shows the same PHP-rendered controls the admin screens use,
	 * which is why a field type works in a block the moment it works anywhere
	 * else. Rebuilding fifty field types in React would be a second
	 * implementation to keep in step with the first.
	 */
	public function block_form(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit blocks.', 'wp-custom-meta-box' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$key    = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '';
		$values = isset( $_POST['values'] ) ? json_decode( (string) wp_unslash( $_POST['values'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded, then each value is sanitized by its own field type.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$group = $this->container->get( Repository::class )->get( $key );

		if ( ! $group instanceof FieldGroup ) {
			wp_send_json_error( array( 'message' => __( 'That block no longer exists.', 'wp-custom-meta-box' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'html' => $this->container->get( BlockRegistry::class )->render_form(
					$group,
					is_array( $values ) ? $values : array()
				),
			)
		);
	}

	/**
	 * Render one blank repeater row.
	 *
	 * Rows come from the server rather than being cloned in the browser
	 * because a row can contain controls that are more than their markup —
	 * a `wp_editor()` clone is a dead editor. Rendering server-side means a
	 * new row is built exactly like the ones already on the page.
	 */
	public function repeater_row(): void {
		$field = $this->verified_field();

		if ( ! $field instanceof \ArrayObject ) {
			return;
		}

		$definition = $field->getArrayCopy();
		$type       = (string) ( $definition['type'] ?? '' );

		if ( ! in_array( $type, array( 'repeater', 'flexible_content' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'That field does not have rows.', 'wp-custom-meta-box' ) ), 400 );
		}

		$handler = $this->container->get( Registry::class )->get( $type );

		if ( ! $handler instanceof Repeater ) {
			wp_send_json_error( array( 'message' => __( 'That field type is unavailable.', 'wp-custom-meta-box' ) ), 500 );
		}

		$name  = $this->request_input_name();
		$index = isset( $_POST['index'] ) ? absint( wp_unslash( $_POST['index'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verified_field().
		$row   = array();

		if ( $handler instanceof Flexible ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verified_field().
			$layout_name = isset( $_POST['layout'] ) ? sanitize_key( wp_unslash( $_POST['layout'] ) ) : '';
			$layouts     = $handler->layouts( $definition );

			if ( ! isset( $layouts[ $layout_name ] ) ) {
				wp_send_json_error( array( 'message' => __( 'That layout does not exist.', 'wp-custom-meta-box' ) ), 400 );
			}

			$sub_fields = $layouts[ $layout_name ]['sub_fields'];
			$row        = array( Flexible::LAYOUT_KEY => $layout_name );
		} else {
			$sub_fields = $handler->sub_fields( $definition );
		}

		ob_start();
		$handler->render_row( $definition, $sub_fields, $row, $index, $name, $this->request_input_id() );

		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * Send a repeater's stored rows as a CSV download.
	 *
	 * Only scalar sub values are exported. A nested repeater or a gallery has
	 * no honest single-cell representation, and flattening one into a cell
	 * would produce a file that cannot be imported back.
	 */
	public function csv_export(): void {
		$field = $this->verified_field();

		if ( ! $field instanceof \ArrayObject ) {
			return;
		}

		$definition = $field->getArrayCopy();
		$handler    = $this->container->get( Registry::class )->get( 'repeater' );

		if ( ! $handler instanceof Repeater ) {
			wp_send_json_error( array( 'message' => __( 'The repeater type is unavailable.', 'wp-custom-meta-box' ) ), 500 );
		}

		$columns = array();

		foreach ( $handler->sub_fields( $definition ) as $sub ) {
			if ( is_array( $sub ) && ! empty( $sub['name'] ) && $this->is_flat( (string) ( $sub['type'] ?? '' ) ) ) {
				$columns[] = (string) $sub['name'];
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in verified_field(); each cell is cast below.
		$rows = isset( $_POST['rows'] ) ? json_decode( (string) wp_unslash( $_POST['rows'] ), true ) : array();
		$rows = is_array( $rows ) ? $rows : array();

		$lines = array( $this->csv_line( $columns ) );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$cells = array();

			foreach ( $columns as $column ) {
				$cell    = $row[ $column ] ?? '';
				$cells[] = is_scalar( $cell ) ? (string) $cell : '';
			}

			$lines[] = $this->csv_line( $cells );
		}

		wp_send_json_success(
			array(
				'filename' => sanitize_file_name( ( $definition['name'] ?? 'repeater' ) . '-' . gmdate( 'Y-m-d' ) . '.csv' ),
				'csv'      => implode( "\r\n", $lines ),
			)
		);
	}

	/**
	 * Parse an uploaded CSV into rows the browser can render.
	 *
	 * Returns parsed rows rather than writing anything: the import lands in
	 * the form, and nothing is stored until the user saves the screen. An
	 * import that wrote directly would be an unreviewable, unundoable change
	 * to whatever the repeater already held.
	 */
	public function csv_import(): void {
		$field = $this->verified_field();

		if ( ! $field instanceof \ArrayObject ) {
			return;
		}

		$definition = $field->getArrayCopy();
		$handler    = $this->container->get( Registry::class )->get( 'repeater' );

		if ( ! $handler instanceof Repeater ) {
			wp_send_json_error( array( 'message' => __( 'The repeater type is unavailable.', 'wp-custom-meta-box' ) ), 500 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in verified_field(); parsed and filtered below.
		$csv = isset( $_POST['csv'] ) ? (string) wp_unslash( $_POST['csv'] ) : '';

		if ( '' === trim( $csv ) ) {
			wp_send_json_error( array( 'message' => __( 'That file was empty.', 'wp-custom-meta-box' ) ), 400 );
		}

		$known = array();

		foreach ( $handler->sub_fields( $definition ) as $sub ) {
			if ( is_array( $sub ) && ! empty( $sub['name'] ) ) {
				$known[] = (string) $sub['name'];
			}
		}

		$parsed  = $this->parse_csv( $csv );
		$headers = array_shift( $parsed );

		if ( null === $headers ) {
			wp_send_json_error( array( 'message' => __( 'That file had no header row.', 'wp-custom-meta-box' ) ), 400 );
		}

		// Columns the repeater does not have are dropped rather than guessed
		// at by position, so a reordered or extended file cannot quietly load
		// values into the wrong fields.
		$map = array();

		foreach ( $headers as $position => $header ) {
			$name = sanitize_key( (string) $header );

			if ( in_array( $name, $known, true ) ) {
				$map[ $position ] = $name;
			}
		}

		if ( array() === $map ) {
			wp_send_json_error(
				array( 'message' => __( 'None of the columns in that file match this repeater\'s fields.', 'wp-custom-meta-box' ) ),
				400
			);
		}

		$rows = array();

		foreach ( $parsed as $cells ) {
			$row = array();

			foreach ( $map as $position => $name ) {
				$row[ $name ] = sanitize_textarea_field( (string) ( $cells[ $position ] ?? '' ) );
			}

			$rows[] = $row;
		}

		wp_send_json_success(
			array(
				'rows'    => $rows,
				'columns' => array_values( $map ),
			)
		);
	}

	/**
	 * Return the oEmbed markup for a URL, so the editor sees it before saving.
	 *
	 * Done on the server because that is where oEmbed already lives: provider
	 * matching, the allow-list of providers and the response cache are all
	 * WordPress's, and none of them exist in the browser. The allow-list is
	 * also what stops this being a general-purpose fetcher pointed at anything
	 * the request names.
	 */
	public function embed_preview(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		// Editing capability, not just being signed in: this makes an outbound
		// request on the site's behalf, so a subscriber should not reach it.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot preview embeds.', 'wp-custom-meta-box' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer() above.
		$url = isset( $_POST['url'] ) ? sanitize_url( wp_unslash( $_POST['url'] ) ) : '';

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'No URL was given.', 'wp-custom-meta-box' ) ), 400 );
		}

		$html = wp_oembed_get( $url );

		if ( ! is_string( $html ) || '' === $html ) {
			wp_send_json_error( array( 'message' => __( 'Nothing could be embedded from that URL.', 'wp-custom-meta-box' ) ), 404 );
		}

		wp_send_json_success( array( 'html' => wp_kses_post( $html ) ) );
	}

	/**
	 * Validate a screen's submitted values without storing anything.
	 *
	 * The gate that stops a post being published with a required field empty
	 * asks this before letting the save start. It runs the real Validator over
	 * the real resolved fields rather than a second copy of the rules in the
	 * browser: conditional logic, per-type formats, lengths, ranges, patterns
	 * and the `wpcmb/validate` filters are all decided in one place, and a
	 * rule added in PHP is enforced by the gate the moment it exists.
	 *
	 * Stores nothing and reveals nothing the caller could not already see: it
	 * answers only about values the caller just sent, for an object the caller
	 * is allowed to edit.
	 */
	public function validate_values(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer() above.
		$ref = ObjectRef::from( isset( $_POST['object'] ) ? sanitize_text_field( wp_unslash( $_POST['object'] ) ) : '' );

		if ( ! Permissions::can_edit( $ref ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this.', 'wp-custom-meta-box' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; every value is read by the validator, never stored or echoed.
		$submitted = isset( $_POST[ Renderer::INPUT_PREFIX ] ) && is_array( $_POST[ Renderer::INPUT_PREFIX ] )
			? wp_unslash( $_POST[ Renderer::INPUT_PREFIX ] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$renderer = $this->container->get( Renderer::class );
		$fields   = array();

		foreach ( $this->container->get( Resolver::class )->fields( new Context( $ref ) ) as $name => $field ) {
			if ( $renderer->stores_value( $field ) ) {
				$fields[ $name ] = $field;
			}
		}

		/*
		 * Every resolved field is validated, including ones the request left
		 * out. That is the opposite of the save path, which skips absent
		 * fields so a partial submission cannot wipe what it never showed —
		 * here an absent required field is exactly the thing being looked for.
		 */
		$values = array();

		foreach ( array_keys( $fields ) as $name ) {
			$values[ $name ] = $submitted[ $name ] ?? null;
		}

		$errors = $this->container->get( Validator::class )->validate( $fields, $values );
		$keys   = array();

		foreach ( $errors as $name => $message ) {
			$keys[ $name ] = (string) ( $fields[ $name ]['key'] ?? '' );
		}

		wp_send_json_success(
			array(
				'valid'  => array() === $errors,
				'errors' => $errors,
				'keys'   => $keys,
			)
		);
	}

	/**
	 * The capability these endpoints require.
	 *
	 * Editing, not merely being signed in. These render a field's own markup
	 * — its labels, choices and default values — so being able to reach them
	 * with nothing but a login turns any subscriber into a reader of the
	 * site's field structure. `edit_posts` is the same bar `block_form()` and
	 * `embed_preview()` already set.
	 *
	 * Filterable because a site can legitimately put a repeater on a user
	 * profile group that subscribers fill in themselves; such a site lowers
	 * this deliberately rather than losing the control by default.
	 */
	public static function capability(): string {
		/**
		 * Filters the capability required to reach the field AJAX endpoints.
		 *
		 * @since 1.1.0
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'wpcmb/ajax/capability', 'edit_posts' );
	}

	/**
	 * Verify the request and resolve the field it names.
	 *
	 * Wrapped in an ArrayObject so the caller can tell "resolved" from a
	 * legitimately empty definition without a second call.
	 *
	 * @return \ArrayObject|null
	 */
	private function verified_field(): ?\ArrayObject {
		check_ajax_referer( self::NONCE, 'nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer() above.
		$key = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( $_POST['field_key'] ) ) : '';

		/*
		 * An editor may ask about any field. A visitor on a front-end form may
		 * ask about the fields of that form and nothing else — a repeater on a
		 * public form is unusable otherwise, because its rows are rendered by
		 * the server and there is nobody with `edit_posts` to render them.
		 */
		if ( ! current_user_can( self::capability() ) && ! $this->form_allows( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit fields.', 'wp-custom-meta-box' ) ), 403 );
		}

		$field = $this->container->get( Repository::class )->field_by_key( $key );

		if ( null === $field ) {
			wp_send_json_error( array( 'message' => __( 'That field no longer exists.', 'wp-custom-meta-box' ) ), 404 );
		}

		return new \ArrayObject( $field );
	}

	/**
	 * Whether a signed front-end form entitles this request to a field.
	 *
	 * The form's configuration travels with it, signed with the site's own
	 * salts, so it cannot be edited by whoever is holding it. That signature
	 * is what stands in for a capability here, and it is only worth anything
	 * alongside three further checks: the form must actually accept this
	 * visitor, the field must belong to the group the form names, and the
	 * group must be one that is published.
	 *
	 * What a visitor can obtain this way is the blank markup of a field on a
	 * form the site chose to publish — which is already on the page they are
	 * looking at.
	 *
	 * @param string $key Field key being requested.
	 */
	private function form_allows( string $key ): bool {
		if ( '' === $key ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_ajax_referer() ran in verified_field(); the signature below is the authorisation.
		$state = isset( $_POST[ Form::STATE ] ) && is_array( $_POST[ Form::STATE ] )
			? wp_unslash( $_POST[ Form::STATE ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified by signature, not by shape.
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$config = Form::verify(
			is_scalar( $state['payload'] ?? null ) ? (string) $state['payload'] : '',
			is_scalar( $state['signature'] ?? null ) ? (string) $state['signature'] : ''
		);

		if ( null === $config || ! Form::may_submit( $config ) ) {
			return false;
		}

		$group = $this->container->get( Repository::class )->get( (string) $config['group'] );

		if ( ! $group instanceof FieldGroup ) {
			return false;
		}

		return null !== $this->container->get( Repository::class )->field_in_group( $group, $key );
	}

	/**
	 * The input name prefix from the request, validated against its shape.
	 *
	 * A name only decides which request key a value posts under, and the save
	 * path reads declared field names only — but it is still checked so that
	 * nothing arbitrary reaches a name attribute.
	 */
	private function request_input_name(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in verified_field(); matched against an exact shape below, which is stricter than any sanitizer.
		$name = isset( $_POST['name'] ) ? (string) wp_unslash( $_POST['name'] ) : '';

		return 1 === preg_match( '/^wpcmb_values(\[[A-Za-z0-9_\-]+\])*$/', $name ) ? $name : 'wpcmb_values';
	}

	/**
	 * The input id prefix from the request, reduced to safe characters.
	 */
	private function request_input_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in verified_field().
		$id = isset( $_POST['id'] ) ? sanitize_html_class( wp_unslash( $_POST['id'] ) ) : '';

		return '' !== $id ? $id : 'wpcmb-field';
	}

	/**
	 * Whether a sub field type has a sensible single-cell representation.
	 *
	 * @param string $type Type name.
	 */
	private function is_flat( string $type ): bool {
		return ! in_array(
			$type,
			array( 'repeater', 'flexible_content', 'group', 'gallery', 'link', 'address', 'tab', 'accordion', 'message' ),
			true
		);
	}

	/**
	 * Encode one CSV line.
	 *
	 * Cells starting with a formula character are prefixed with a quote so a
	 * spreadsheet treats them as text. Without it, an exported value such as
	 * `=1+1` becomes a live formula, and `=HYPERLINK(...)` becomes something
	 * worse the moment somebody opens the file.
	 *
	 * @param array<int, string> $cells Cell values.
	 */
	private function csv_line( array $cells ): string {
		$escaped = array();

		foreach ( $cells as $cell ) {
			if ( 1 === preg_match( '/^[=+\-@\t\r]/', $cell ) ) {
				$cell = "'" . $cell;
			}

			$escaped[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}

		return implode( ',', $escaped );
	}

	/**
	 * Parse CSV text into rows of cells.
	 *
	 * @param string $csv CSV text.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function parse_csv( string $csv ): array {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- An in-memory stream, not the filesystem.

		if ( false === $handle ) {
			return array();
		}

		fwrite( $handle, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- An in-memory stream.
		rewind( $handle );

		$rows = array();

		while ( true ) {
			$cells = fgetcsv( $handle, 0, ',', '"', '\\' );

			if ( false === $cells || null === $cells ) {
				break;
			}

			// fgetcsv reports a blank line as a single null cell.
			if ( array( null ) === $cells ) {
				continue;
			}

			$rows[] = array_map( static fn( $cell ): string => (string) $cell, $cells );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- An in-memory stream.

		return $rows;
	}
}
