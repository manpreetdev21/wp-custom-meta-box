<?php
/**
 * The `wp wpcmb` command.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\CLI;

use WPCMB\Container;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Validator;
use WPCMB\Fields\Values;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Manage field groups and values from the command line.
 *
 * Deliberately narrow: listing groups, reading and writing values, and moving
 * field groups between environments. Those are what a deploy script or a
 * migration needs, and each is a thin shell over the same services the admin
 * screens use rather than a second way of doing the work.
 *
 * Every public method here is a subcommand, so nothing else belongs on this
 * class.
 */
final class FieldCommand {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * List field groups.
	 *
	 * ## OPTIONS
	 *
	 * [--inactive]
	 * : Include groups that are not active.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb groups
	 *     wp wpcmb groups --inactive --format=json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function groups( $args, $assoc_args ): void {
		$groups = $this->container->get( Repository::class )->all( isset( $assoc_args['inactive'] ) );

		$rows = array();

		foreach ( $groups as $group ) {
			$rows[] = array(
				'key'    => $group->key,
				'title'  => $group->title,
				'fields' => count( $group->fields ),
				'active' => $group->is_active() ? 'yes' : 'no',
				'block'  => ! empty( $group->settings['block_enabled'] ) ? (string) $group->settings['block_name'] : '',
			);
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'key', 'title', 'fields', 'active', 'block' )
		);
	}

	/**
	 * Read a field value.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Field name.
	 *
	 * <object>
	 * : Object identifier, e.g. 12, post_12, term_5, user_2 or options.
	 *
	 * [--raw]
	 * : Return the stored value without field-type formatting.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 *   - var_export
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb get byline 12
	 *     wp wpcmb get api_key options --raw
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function get( $args, $assoc_args ): void {
		$ref = $this->resolve( $args[1] );

		$value = $this->container->get( Values::class )->get( $args[0], $ref, ! isset( $assoc_args['raw'] ) );

		\WP_CLI::print_value( $value, array( 'format' => (string) ( $assoc_args['format'] ?? 'json' ) ) );
	}

	/**
	 * Write a field value.
	 *
	 * The value is validated and sanitized exactly as it would be from an
	 * edit screen, so a script cannot store something the admin would refuse.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Field name.
	 *
	 * <object>
	 * : Object identifier.
	 *
	 * <value>
	 * : The value. Parsed as JSON when it is valid JSON, so structured fields
	 * : can be written from a script.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb update byline 12 "Manpreet Singh"
	 *     wp wpcmb update rows 12 '[{"title":"One"},{"title":"Two"}]'
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function update( $args ): void {
		list( $name, $identifier, $raw ) = $args;

		$ref   = $this->resolve( $identifier );
		$field = $this->container->get( Repository::class )->field_by_name( $name );

		if ( null === $field ) {
			\WP_CLI::error( sprintf( 'No field named "%s".', $name ) );
		}

		$decoded = json_decode( $raw, true );
		$value   = ( null === $decoded && 'null' !== $raw ) ? $raw : $decoded;

		$errors = $this->container->get( Validator::class )->validate(
			array( $name => $field ),
			array( $name => $value )
		);

		if ( array() !== $errors ) {
			\WP_CLI::error( (string) reset( $errors ) );
		}

		$clean = $this->container->get( Renderer::class )->sanitize( $value, $field );

		$this->container->get( Values::class )->update( $name, $clean, $ref );

		\WP_CLI::success( sprintf( 'Updated "%s" on %s.', $name, (string) $ref ) );
	}

	/**
	 * Delete a field value.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Field name.
	 *
	 * <object>
	 * : Object identifier.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb delete byline 12
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function delete( $args ): void {
		$ref = $this->resolve( $args[1] );

		if ( ! $this->container->get( Values::class )->delete( $args[0], $ref ) ) {
			\WP_CLI::warning( sprintf( 'Nothing stored for "%s" on %s.', $args[0], (string) $ref ) );

			return;
		}

		\WP_CLI::success( sprintf( 'Deleted "%s" from %s.', $args[0], (string) $ref ) );
	}

	/**
	 * Export field groups as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [<key>...]
	 * : Group keys to export. Omit for all of them.
	 *
	 * [--file=<path>]
	 * : Write to a file instead of standard output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb export > groups.json
	 *     wp wpcmb export group_abc123 --file=groups.json
	 *
	 * @param array<int, string>    $args       Group keys.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function export( $args, $assoc_args ): void {
		$groups = $this->container->get( Repository::class )->all( true );

		if ( array() !== $args ) {
			$groups = array_intersect_key( $groups, array_flip( $args ) );
		}

		if ( array() === $groups ) {
			\WP_CLI::error( 'No matching field groups.' );
		}

		$json = (string) wp_json_encode(
			array_values( array_map( static fn( FieldGroup $g ): array => $g->to_array(), $groups ) ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		$file = (string) ( $assoc_args['file'] ?? '' );

		if ( '' === $file ) {
			\WP_CLI::line( $json );

			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A path the operator gave us, on their own machine.
		if ( false === file_put_contents( $file, $json ) ) {
			\WP_CLI::error( sprintf( 'Could not write to %s.', $file ) );
		}

		\WP_CLI::success( sprintf( 'Exported %d field groups to %s.', count( $groups ), $file ) );
	}

	/**
	 * Import field groups from a JSON export.
	 *
	 * A group whose key already exists is updated in place. Anything new is
	 * created inactive, so an import cannot quietly start showing fields on a
	 * production site before somebody has looked at them.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a JSON export.
	 *
	 * [--activate]
	 * : Publish newly created groups instead of leaving them inactive.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcmb import groups.json
	 *     wp wpcmb import groups.json --activate
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function import( $args, $assoc_args ): void {
		$file = $args[0];

		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local path the operator gave us.
		$decoded = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $decoded ) || array() === $decoded ) {
			\WP_CLI::error( 'That file is not a field group export.' );
		}

		if ( isset( $decoded['key'] ) || isset( $decoded['fields'] ) ) {
			$decoded = array( $decoded );
		}

		$repository = $this->container->get( Repository::class );
		$status     = isset( $assoc_args['activate'] ) ? 'publish' : 'draft';
		$imported   = 0;

		foreach ( $decoded as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$config   = FieldGroup::sanitize( $raw );
			$existing = $repository->get( $config['key'] );
			$post_id  = $existing instanceof FieldGroup ? $existing->id : 0;

			if ( 0 === $post_id ) {
				$post_id = wp_insert_post(
					array(
						'post_type'   => FieldGroupPostType::POST_TYPE,
						'post_status' => $status,
						'post_title'  => '' !== $config['title'] ? $config['title'] : 'Imported field group',
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					\WP_CLI::warning( $post_id->get_error_message() );
					continue;
				}
			} else {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $config['title'],
					)
				);
			}

			$repository->save( (int) $post_id, $config );
			++$imported;

			\WP_CLI::log( sprintf( '  %s (%s)', $config['title'], $config['key'] ) );
		}

		if ( 0 === $imported ) {
			\WP_CLI::error( 'Nothing was imported.' );
		}

		\WP_CLI::success( sprintf( 'Imported %d field groups.', $imported ) );
	}

	/**
	 * Resolve an object identifier, or stop.
	 *
	 * @param string $identifier Object identifier.
	 */
	private function resolve( string $identifier ): ObjectRef {
		$ref = ObjectRef::from( $identifier );

		if ( ! $ref->is_valid() ) {
			\WP_CLI::error( sprintf( 'Could not resolve "%s" to an object.', $identifier ) );
		}

		return $ref;
	}
}
