<?php
/**
 * Field value storage.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes field values.
 *
 * Values live in the native meta tables — post, term, user and comment meta,
 * or the options table for options pages and widgets. There is no custom
 * value table, so every value is already visible to `WP_Query`'s meta_query,
 * to the REST meta endpoints, to WP-CLI, to migration tools and to database
 * exports without this plugin installed.
 *
 * A value is stored under the field's own name, as one meta row. Structured
 * values (repeaters, groups, flexible content) are stored whole rather than
 * flattened into a row per sub value: one row means one read for a repeater
 * of any size, and deleting a row cannot leave orphaned sub values behind.
 *
 * ponytail: whole-value storage means sub values are not individually
 * queryable by meta_query. If sorting or filtering by a sub field is ever
 * needed, mirror just those sub fields into flat companion keys on save
 * rather than flattening the canonical storage.
 */
final class Values {

	/**
	 * Field group repository, used to resolve defaults.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Field group repository.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Read a field value.
	 *
	 * Falls back to the field's configured default when nothing is stored,
	 * so a newly added field behaves the same as one that has been saved.
	 *
	 * @param string $name       Field name.
	 * @param mixed  $identifier Object identifier, see ObjectRef::from().
	 * @param bool   $format     Whether to run the value through field-type formatting.
	 *                           Pass false for the value exactly as stored.
	 *
	 * @return mixed The stored value, the field default, or null.
	 */
	public function get( string $name, mixed $identifier = null, bool $format = true ): mixed {
		$ref   = ObjectRef::from( $identifier );
		$field = $this->repository->field_by_name( $name );

		/**
		 * Short-circuits a value read.
		 *
		 * Return anything other than null to supply the value without
		 * touching storage. Blocks use this so that the same template
		 * functions work inside a block render callback, where values live in
		 * the block's attributes rather than in an object's meta.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed                     $value Null to read normally.
		 * @param string                    $name  Field name.
		 * @param ObjectRef                 $ref   Object the value would belong to.
		 * @param array<string, mixed>|null $field Field definition, if known.
		 */
		$pre = apply_filters( 'wpcmb/value/pre_get', null, $name, $ref, $field );

		if ( null !== $pre ) {
			return $pre;
		}

		$value = $this->read( $name, $ref );

		if ( null === $value ) {
			$value = $field['default'] ?? null;
		}

		if ( ! $format ) {
			return $value;
		}

		/**
		 * Filters a field value as it is read.
		 *
		 * Field types hook this to turn stored data into the shape the
		 * template API returns, e.g. an attachment id into a WP_Post.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed                     $value Stored value or default.
		 * @param string                    $name  Field name.
		 * @param ObjectRef                 $ref   Object the value belongs to.
		 * @param array<string, mixed>|null $field Field definition, if known.
		 */
		return apply_filters( 'wpcmb/value/load', $value, $name, $ref, $field );
	}

	/**
	 * Write a field value.
	 *
	 * @param string $name       Field name.
	 * @param mixed  $value      Value to store, unslashed.
	 * @param mixed  $identifier Object identifier, see ObjectRef::from().
	 *
	 * @return bool Whether the value was written.
	 */
	public function update( string $name, mixed $value, mixed $identifier = null ): bool {
		$ref = ObjectRef::from( $identifier );

		if ( ! $ref->is_valid() ) {
			return false;
		}

		$field = $this->repository->field_by_name( $name );

		/**
		 * Filters a field value before it is stored.
		 *
		 * Field types hook this to cast and sanitize input for their type.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed                     $value Value to store.
		 * @param string                    $name  Field name.
		 * @param ObjectRef                 $ref   Object the value belongs to.
		 * @param array<string, mixed>|null $field Field definition, if known.
		 */
		$value = apply_filters( 'wpcmb/value/save', $value, $name, $ref, $field );

		$written = $this->write( $name, $value, $ref );

		if ( $written ) {
			/**
			 * Fires after a field value is written.
			 *
			 * @since 1.0.0
			 *
			 * @param string    $name  Field name.
			 * @param mixed     $value Stored value.
			 * @param ObjectRef $ref   Object the value belongs to.
			 */
			do_action( 'wpcmb/value/updated', $name, $value, $ref );
		}

		return $written;
	}

	/**
	 * Delete a field value.
	 *
	 * @param string $name       Field name.
	 * @param mixed  $identifier Object identifier, see ObjectRef::from().
	 */
	public function delete( string $name, mixed $identifier = null ): bool {
		$ref = ObjectRef::from( $identifier );

		if ( ! $ref->is_valid() ) {
			return false;
		}

		/**
		 * Short-circuits deleting a value from storage.
		 *
		 * @since 1.0.0
		 *
		 * @param bool|null $deleted Null to delete normally.
		 * @param string    $name    Field name.
		 * @param ObjectRef $ref     Object reference.
		 */
		$claimed = apply_filters( 'wpcmb/storage/delete', null, $name, $ref );

		if ( null !== $claimed ) {
			$deleted = (bool) $claimed;
		} elseif ( ObjectRef::OPTION === $ref->type ) {
			$deleted = delete_option( $this->option_name( $name, $ref ) );
		} else {
			$deleted = delete_metadata( $ref->type, (int) $ref->id, $name );
		}

		if ( $deleted ) {
			/**
			 * Fires after a field value is deleted.
			 *
			 * @since 1.0.0
			 *
			 * @param string    $name Field name.
			 * @param ObjectRef $ref  Object the value belonged to.
			 */
			do_action( 'wpcmb/value/deleted', $name, $ref );
		}

		return (bool) $deleted;
	}

	/**
	 * Whether a value is stored, ignoring any default.
	 *
	 * @param string $name       Field name.
	 * @param mixed  $identifier Object identifier, see ObjectRef::from().
	 */
	public function has( string $name, mixed $identifier = null ): bool {
		return null !== $this->read( $name, ObjectRef::from( $identifier ) );
	}

	/**
	 * Read the raw stored value, or null when nothing is stored.
	 *
	 * Distinguishing "not stored" from "stored as an empty string" is what
	 * lets defaults apply only to fields that have never been saved.
	 *
	 * @param string    $name Field name.
	 * @param ObjectRef $ref  Object reference.
	 */
	private function read( string $name, ObjectRef $ref ): mixed {
		if ( ! $ref->is_valid() ) {
			return null;
		}

		/**
		 * Short-circuits reading a value from storage.
		 *
		 * Return anything other than null to supply the stored value.
		 * Integrations use this for objects that do not keep their meta where
		 * this class would look — a WooCommerce order under HPOS lives in its
		 * own table, not in `wp_postmeta`.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed     $value Null to read normally.
		 * @param string    $name  Field name.
		 * @param ObjectRef $ref   Object reference.
		 */
		$stored = apply_filters( 'wpcmb/storage/read', null, $name, $ref );

		if ( null !== $stored ) {
			return $stored;
		}

		if ( ObjectRef::OPTION === $ref->type ) {
			$sentinel = new \stdClass();
			$value    = get_option( $this->option_name( $name, $ref ), $sentinel );

			return $value === $sentinel ? null : $value;
		}

		$stored = get_metadata( $ref->type, (int) $ref->id, $name, false );

		return is_array( $stored ) && array() !== $stored ? $stored[0] : null;
	}

	/**
	 * Write the raw value.
	 *
	 * Meta writes are slashed on the way in because update_metadata() runs
	 * wp_unslash() on whatever it is given; passing raw data would eat every
	 * backslash in it. Options are not unslashed, so they are written as-is.
	 *
	 * @param string    $name  Field name.
	 * @param mixed     $value Value to store.
	 * @param ObjectRef $ref   Object reference.
	 */
	private function write( string $name, mixed $value, ObjectRef $ref ): bool {
		/**
		 * Short-circuits writing a value to storage.
		 *
		 * Return a boolean to claim the write. Pairs with
		 * `wpcmb/storage/read` for objects that keep meta elsewhere.
		 *
		 * @since 1.0.0
		 *
		 * @param bool|null $written Null to write normally.
		 * @param string    $name    Field name.
		 * @param mixed     $value   Value to store.
		 * @param ObjectRef $ref     Object reference.
		 */
		$claimed = apply_filters( 'wpcmb/storage/write', null, $name, $value, $ref );

		if ( null !== $claimed ) {
			return (bool) $claimed;
		}

		if ( ObjectRef::OPTION === $ref->type ) {
			return update_option( $this->option_name( $name, $ref ), $value, false );
		}

		return (bool) update_metadata( $ref->type, (int) $ref->id, $name, wp_slash( $value ) );
	}

	/**
	 * The option name a field value is stored under.
	 *
	 * Namespaced by plugin prefix and options page so two pages can use the
	 * same field name without colliding.
	 *
	 * @param string    $name Field name.
	 * @param ObjectRef $ref  Object reference.
	 */
	private function option_name( string $name, ObjectRef $ref ): string {
		return 'wpcmb_' . $ref->id . '_' . $name;
	}
}
