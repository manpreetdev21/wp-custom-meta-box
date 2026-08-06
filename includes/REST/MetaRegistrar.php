<?php
/**
 * Meta registration for the core REST endpoints.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\REST;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Permissions;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers field values as typed meta, so core exposes them itself.
 *
 * This is the whole REST story for values. `register_meta()` with a schema
 * gives read and write on `/wp/v2/posts/1`, `/wp/v2/users/1`,
 * `/wp/v2/tags/1` and `/wp/v2/comments/1`, plus authentication, per-object
 * permission checks, request validation, type casting and self-documenting
 * schema output — all of it maintained by WordPress. A bespoke value
 * endpoint would reimplement every one of those and still not appear
 * alongside the post it belongs to.
 *
 * Which object types a group registers against comes from its own location
 * rules, so a group targeting pages does not put its fields on users.
 */
final class MetaRegistrar extends Module {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		// After the post types a group might target have been registered.
		add_action( 'init', array( $this, 'register' ), 20 );
	}

	/**
	 * Register every exposable field as meta.
	 */
	public function register(): void {
		foreach ( $this->container->get( Repository::class )->all() as $group ) {
			foreach ( $this->targets( $group ) as $object_type => $subtypes ) {
				foreach ( $subtypes as $subtype ) {
					$this->register_group( $group, $object_type, $subtype );
				}
			}
		}
	}

	/**
	 * Register one group's fields against one object type and subtype.
	 *
	 * @param FieldGroup $group       Field group.
	 * @param string     $object_type Meta object type.
	 * @param string     $subtype     Object subtype, empty for all.
	 */
	private function register_group( FieldGroup $group, string $object_type, string $subtype ): void {
		$renderer = $this->container->get( Renderer::class );

		foreach ( $group->fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}

			if ( ! $renderer->stores_value( $field ) || ! Schema::is_exposable( $field ) ) {
				continue;
			}

			$name   = (string) $field['name'];
			$schema = Schema::for_field( $field );

			register_meta(
				$object_type,
				$name,
				array(
					'object_subtype'    => $subtype,
					'type'              => (string) $schema['type'],
					'description'       => (string) ( $schema['description'] ?? $name ),
					'single'            => true,
					'show_in_rest'      => array( 'schema' => $schema ),

					// Values are cleaned by their own field type, whether they
					// arrive from an edit screen, a form or a REST request.
					'sanitize_callback' => static fn( $value ) => $renderer->sanitize( $value, $field ),

					// Core calls this with six arguments; only the object id
					// matters here, because the object decides the answer.
					'auth_callback'     => static fn( $allowed, $meta_key, $object_id ): bool =>
						Permissions::can_edit( new ObjectRef( self::ref_type( $object_type ), (int) $object_id ) ),
				)
			);
		}
	}

	/**
	 * The object types and subtypes a group's location rules target.
	 *
	 * A rule that names no subtype registers against every subtype, which is
	 * what an empty string means to register_meta().
	 *
	 * @param FieldGroup $group Field group.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function targets( FieldGroup $group ): array {
		$targets = array();

		foreach ( $group->location as $rules ) {
			foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
				if ( ! is_array( $rule ) || '==' !== ( $rule['operator'] ?? '==' ) ) {
					continue;
				}

				$param = (string) ( $rule['param'] ?? '' );
				$value = (string) ( $rule['value'] ?? '' );

				$target = match ( $param ) {
					'post_type', 'post', 'page', 'post_template', 'page_template', 'post_status', 'post_format'
						=> array( 'post', 'post_type' === $param ? $value : '' ),
					'attachment'    => array( 'post', 'attachment' ),
					'nav_menu_item' => array( 'post', 'nav_menu_item' ),
					'taxonomy'      => array( 'term', $value ),
					'nav_menu'      => array( 'term', 'nav_menu' ),
					'user_role', 'user_form' => array( 'user', '' ),
					'comment'       => array( 'comment', '' ),
					default         => null,
				};

				if ( null === $target ) {
					continue;
				}

				$targets[ $target[0] ][] = $target[1];
			}
		}

		foreach ( $targets as $object_type => $subtypes ) {
			$targets[ $object_type ] = array_values( array_unique( $subtypes ) );
		}

		/**
		 * Filters the object types a group's fields are registered against.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<int, string>> $targets    Subtypes keyed by object type.
		 * @param FieldGroup                        $group      Field group.
		 */
		return (array) apply_filters( 'wpcmb/rest/meta_targets', $targets, $group );
	}

	/**
	 * Map a meta object type to an ObjectRef type.
	 *
	 * @param string $object_type Meta object type.
	 */
	private static function ref_type( string $object_type ): string {
		return match ( $object_type ) {
			'term'    => ObjectRef::TERM,
			'user'    => ObjectRef::USER,
			'comment' => ObjectRef::COMMENT,
			default   => ObjectRef::POST,
		};
	}
}
