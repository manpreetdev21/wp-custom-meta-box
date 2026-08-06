<?php
/**
 * JSON Schema for field values.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\REST;

use WPCMB\FieldTypes\Flexible;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a field definition into the JSON Schema that describes its value.
 *
 * This is what makes REST work without a line of per-endpoint validation:
 * WordPress validates, sanitizes, documents and type-casts anything it has a
 * schema for. Writing the schema once per field type buys all of that for
 * every field on the site, on core's own endpoints as well as this plugin's.
 *
 * Schemas are derived from the field definition rather than hand-written, so
 * adding a sub field to a repeater updates the endpoint's contract with it.
 */
final class Schema {

	/**
	 * The schema for a field's value.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param int                  $depth Current nesting depth.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_field( array $field, int $depth = 0 ): array {
		$type = (string) ( $field['type'] ?? 'text' );

		$schema = match ( true ) {
			'repeater' === $type          => self::rows( $field, $depth ),
			'flexible_content' === $type  => self::layout_rows( $field ),
			'group' === $type             => self::object_of( self::properties( $field['sub_fields'] ?? array(), $depth ) ),
			'link' === $type              => self::link(),
			'address' === $type           => self::address(),
			'gallery' === $type           => self::list_of( array( 'type' => 'integer' ) ),
			'relationship' === $type      => self::list_of( array( 'type' => 'integer' ) ),
			'checkbox' === $type          => self::list_of( array( 'type' => 'string' ) ),
			'toggle' === $type            => array( 'type' => 'boolean' ),
			in_array( $type, array( 'number', 'range', 'currency', 'rating' ), true ) => array( 'type' => 'number' ),
			in_array( $type, array( 'file', 'image', 'video', 'audio', 'post_object', 'taxonomy', 'user' ), true ) => array( 'type' => 'integer' ),
			default                       => self::string_schema( $field ),
		};

		if ( '' !== (string) ( $field['label'] ?? '' ) ) {
			$schema['description'] = (string) $field['label'];
		}

		/**
		 * Filters the JSON Schema describing a field's value.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $schema The schema.
		 * @param array<string, mixed> $field  Field definition.
		 */
		return (array) apply_filters( 'wpcmb/rest/field_schema', $schema, $field );
	}

	/**
	 * Whether a field's value can be represented in REST at all.
	 *
	 * Structural fields hold nothing, so exposing them would advertise
	 * properties that can never be read or written.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	public static function is_exposable( array $field ): bool {
		return ! in_array( (string) ( $field['type'] ?? '' ), array( 'message', 'tab', 'accordion' ), true );
	}

	/**
	 * A string schema, narrowed by format where the type implies one.
	 *
	 * The format is what lets core reject a malformed email or URI before
	 * any of this plugin's code runs.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, mixed>
	 */
	private static function string_schema( array $field ): array {
		$schema = array( 'type' => 'string' );

		$format = match ( (string) ( $field['type'] ?? '' ) ) {
			'email'    => 'email',
			'url'      => 'uri',
			'date'     => 'date',
			'datetime' => 'date-time',
			'color'    => 'hex-color',
			default    => '',
		};

		if ( '' !== $format ) {
			$schema['format'] = $format;
		}

		$choices = self::choices( $field );

		if ( array() !== $choices ) {
			$schema['enum'] = $choices;
		}

		return $schema;
	}

	/**
	 * A repeater's rows.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param int                  $depth Current depth.
	 *
	 * @return array<string, mixed>
	 */
	private static function rows( array $field, int $depth ): array {
		return self::list_of( self::object_of( self::properties( $field['sub_fields'] ?? array(), $depth ) ) );
	}

	/**
	 * Flexible content rows.
	 *
	 * Rows of different layouts have different shapes, so the item schema
	 * describes only what every row has — the layout name — and leaves the
	 * rest open. A union of every layout's properties would let a row of one
	 * layout claim another's fields and still validate.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, mixed>
	 */
	private static function layout_rows( array $field ): array {
		$names = array();

		foreach ( is_array( $field['layouts'] ?? null ) ? $field['layouts'] : array() as $layout ) {
			if ( is_array( $layout ) && ! empty( $layout['name'] ) ) {
				$names[] = (string) $layout['name'];
			}
		}

		return self::list_of(
			array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(
					Flexible::LAYOUT_KEY => array(
						'type'        => 'string',
						'enum'        => $names,
						'description' => __( 'Which layout this row uses.', 'wp-custom-meta-box' ),
					),
				),
			)
		);
	}

	/**
	 * Schemas for a set of sub fields, keyed by name.
	 *
	 * @param mixed $sub_fields Sub field definitions.
	 * @param int   $depth      Current depth.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function properties( mixed $sub_fields, int $depth ): array {
		// The same depth limit the configuration sanitizer uses, so a schema
		// can never be deeper than the data it describes.
		if ( ! is_array( $sub_fields ) || $depth > 10 ) {
			return array();
		}

		$properties = array();

		foreach ( $sub_fields as $sub ) {
			if ( ! is_array( $sub ) || empty( $sub['name'] ) || ! self::is_exposable( $sub ) ) {
				continue;
			}

			$properties[ (string) $sub['name'] ] = self::for_field( $sub, $depth + 1 );
		}

		return $properties;
	}

	/**
	 * A link's three parts.
	 *
	 * @return array<string, mixed>
	 */
	private static function link(): array {
		return self::object_of(
			array(
				'url'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'title'  => array( 'type' => 'string' ),
				'target' => array(
					'type' => 'string',
					'enum' => array( '', '_blank' ),
				),
			)
		);
	}

	/**
	 * An address's parts.
	 *
	 * @return array<string, mixed>
	 */
	private static function address(): array {
		$properties = array();

		foreach ( array( 'line1', 'line2', 'city', 'region', 'postcode', 'country' ) as $part ) {
			$properties[ $part ] = array( 'type' => 'string' );
		}

		return self::object_of( $properties );
	}

	/**
	 * The allowed values of a choice field, if it declares a fixed set.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<int, string>
	 */
	private static function choices( array $field ): array {
		if ( ! in_array( (string) ( $field['type'] ?? '' ), array( 'select', 'radio', 'button_group' ), true ) ) {
			return array();
		}

		$raw = $field['settings']['choices'] ?? '';

		if ( is_array( $raw ) ) {
			return array_map( 'strval', array_keys( $raw ) );
		}

		$values = array();
		$lines  = preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$values[] = str_contains( $line, ':' ) ? trim( explode( ':', $line, 2 )[0] ) : $line;
		}

		// An empty string is always allowed: a field that is not required
		// must be clearable, and an enum without it would reject that.
		return array() === $values ? array() : array_merge( array( '' ), $values );
	}

	/**
	 * An object schema with the given properties.
	 *
	 * @param array<string, array<string, mixed>> $properties Property schemas.
	 *
	 * @return array<string, mixed>
	 */
	private static function object_of( array $properties ): array {
		return array(
			'type'                 => 'object',
			// Undeclared keys are dropped rather than rejected, so adding a
			// sub field does not break clients still sending the old shape.
			'additionalProperties' => false,
			'properties'           => $properties,
		);
	}

	/**
	 * An array schema of the given item type.
	 *
	 * @param array<string, mixed> $items Item schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function list_of( array $items ): array {
		return array(
			'type'  => 'array',
			'items' => $items,
		);
	}
}
