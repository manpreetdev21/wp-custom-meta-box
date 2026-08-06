<?php
/**
 * The plugin's own REST routes.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\REST;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Context;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Permissions;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Repository;
use WPCMB\Fields\Resolver;
use WPCMB\Fields\Validator;
use WPCMB\Fields\Values;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Routes for the two things core cannot already expose.
 *
 * Field values on posts, terms, users and comments are served by core's own
 * endpoints — see MetaRegistrar. What is left is the field group
 * configuration, which core knows nothing about, and values on options pages,
 * which have no core object to hang off.
 *
 * Both route groups declare a schema, so `OPTIONS` on either returns a full
 * description of what it accepts and returns. That is the documentation: a
 * hand-written list of routes goes stale, a schema derived from the field
 * definitions cannot.
 */
final class Controller extends Module {

	/**
	 * Route namespace.
	 */
	public const NAMESPACE = 'wpcmb/v1';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/field-groups',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_groups' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => array(
						'inactive' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Include groups that are not active.', 'wp-custom-meta-box' ),
						),
					),
				),
				'schema' => array( $this, 'group_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/field-groups/(?P<key>group_[a-z0-9]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_group' ),
					'permission_callback' => array( $this, 'may_manage' ),
				),
				'schema' => array( $this, 'group_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/values/(?P<type>post|term|user|comment|option)/(?P<id>[A-Za-z0-9_\-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_values' ),
					'permission_callback' => array( $this, 'may_read' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_values' ),
					'permission_callback' => array( $this, 'may_edit' ),
					'args'                => array(
						'values' => array(
							'type'        => 'object',
							'required'    => true,
							'description' => __( 'Field values keyed by field name.', 'wp-custom-meta-box' ),
						),
					),
				),
				'schema' => array( $this, 'values_schema' ),
			)
		);
	}

	/**
	 * Whether the request may manage field groups.
	 *
	 * @return true|\WP_Error
	 */
	public function may_manage() {
		if ( current_user_can( FieldGroupPostType::capability() ) ) {
			return true;
		}

		return new \WP_Error(
			'wpcmb_forbidden',
			__( 'You are not allowed to view field groups.', 'wp-custom-meta-box' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Whether the request may read the referenced object's values.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function may_read( $request ) {
		return Permissions::can_read( $this->ref( $request ) )
			? true
			: new \WP_Error(
				'wpcmb_forbidden',
				__( 'You are not allowed to read these values.', 'wp-custom-meta-box' ),
				array( 'status' => rest_authorization_required_code() )
			);
	}

	/**
	 * Whether the request may write the referenced object's values.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return true|\WP_Error
	 */
	public function may_edit( $request ) {
		return Permissions::can_edit( $this->ref( $request ) )
			? true
			: new \WP_Error(
				'wpcmb_forbidden',
				__( 'You are not allowed to edit these values.', 'wp-custom-meta-box' ),
				array( 'status' => rest_authorization_required_code() )
			);
	}

	/**
	 * List field groups.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_groups( $request ): \WP_REST_Response {
		$groups = $this->container->get( Repository::class )->all( (bool) $request['inactive'] );

		return rest_ensure_response( array_values( array_map( array( $this, 'prepare_group' ), $groups ) ) );
	}

	/**
	 * Fetch one field group.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_group( $request ) {
		$group = $this->container->get( Repository::class )->get( (string) $request['key'] );

		if ( ! $group instanceof FieldGroup ) {
			return new \WP_Error(
				'wpcmb_not_found',
				__( 'No field group with that key.', 'wp-custom-meta-box' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->prepare_group( $group ) );
	}

	/**
	 * Read an object's field values.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_values( $request ): \WP_REST_Response {
		$ref    = $this->ref( $request );
		$values = $this->container->get( Values::class );
		$result = array();

		foreach ( array_keys( $this->fields( $ref ) ) as $name ) {
			$result[ $name ] = $values->get( $name, $ref );
		}

		return rest_ensure_response(
			array(
				'object' => (string) $ref,
				'values' => $result,
			)
		);
	}

	/**
	 * Write an object's field values.
	 *
	 * Only fields that actually apply to the object are written, and each one
	 * is validated and then sanitized by its own type — the same path an edit
	 * screen and a front-end form take, so a REST write cannot store anything
	 * the other two would have refused.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_values( $request ) {
		$ref       = $this->ref( $request );
		$fields    = $this->fields( $ref );
		$submitted = (array) $request['values'];
		$present   = array_intersect_key( $submitted, $fields );

		if ( array() === $present ) {
			return new \WP_Error(
				'wpcmb_no_fields',
				__( 'None of those field names apply to this object.', 'wp-custom-meta-box' ),
				array( 'status' => 400 )
			);
		}

		$errors = $this->container->get( Validator::class )->validate(
			array_intersect_key( $fields, $present ),
			$present
		);

		if ( array() !== $errors ) {
			return new \WP_Error(
				'wpcmb_invalid_values',
				__( 'Some values were rejected.', 'wp-custom-meta-box' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		$renderer = $this->container->get( Renderer::class );
		$values   = $this->container->get( Values::class );
		$stored   = array();

		foreach ( $present as $name => $value ) {
			$stored[ $name ] = $renderer->sanitize( $value, $fields[ $name ] );
			$values->update( $name, $stored[ $name ], $ref );
		}

		/** This action is documented in includes/Admin/MetaBoxes.php */
		do_action( 'wpcmb/values/saved', $ref, $stored, array() );

		return rest_ensure_response(
			array(
				'object' => (string) $ref,
				'values' => $stored,
			)
		);
	}

	/**
	 * The object a values request refers to.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function ref( $request ): ObjectRef {
		$type = (string) $request['type'];
		$id   = (string) $request['id'];

		return ObjectRef::from( 'option' === $type ? 'options_' . $id : $type . '_' . $id );
	}

	/**
	 * The fields that apply to an object and can be carried over REST.
	 *
	 * Structural fields are dropped here rather than at each call site: this
	 * list is what a read returns and what a write will accept, and the two
	 * must be the same set. Returning a tab in a read would advertise a
	 * property that can never be written back.
	 *
	 * @param ObjectRef $ref Object reference.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fields( ObjectRef $ref ): array {
		$renderer = $this->container->get( Renderer::class );

		return array_filter(
			$this->container->get( Resolver::class )->fields( new Context( $ref ) ),
			static fn( array $field ): bool => $renderer->stores_value( $field ) && Schema::is_exposable( $field )
		);
	}

	/**
	 * Shape a field group for a response.
	 *
	 * The stored configuration plus the JSON Schema for each field, so a
	 * client can build a form from one request.
	 *
	 * @param FieldGroup $group Field group.
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_group( FieldGroup $group ): array {
		$schemas = array();

		foreach ( $group->fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['name'] ) && Schema::is_exposable( $field ) ) {
				$schemas[ (string) $field['name'] ] = Schema::for_field( $field );
			}
		}

		return $group->to_array() + array(
			'id'     => $group->id,
			'active' => $group->is_active(),
			'schema' => $schemas,
		);
	}

	/**
	 * The schema for a field group response.
	 *
	 * @return array<string, mixed>
	 */
	public function group_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wpcmb_field_group',
			'type'       => 'object',
			'properties' => array(
				'key'      => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'title'    => array( 'type' => 'string' ),
				'active'   => array(
					'type'     => 'boolean',
					'readonly' => true,
				),
				'fields'   => array( 'type' => 'array' ),
				'location' => array( 'type' => 'array' ),
				'settings' => array( 'type' => 'object' ),
				'schema'   => array(
					'type'        => 'object',
					'readonly'    => true,
					'description' => __( 'JSON Schema for each field in this group.', 'wp-custom-meta-box' ),
				),
			),
		);
	}

	/**
	 * The schema for a values response.
	 *
	 * @return array<string, mixed>
	 */
	public function values_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wpcmb_values',
			'type'       => 'object',
			'properties' => array(
				'object' => array(
					'type'     => 'string',
					'readonly' => true,
				),
				'values' => array( 'type' => 'object' ),
			),
		);
	}
}
