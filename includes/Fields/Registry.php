<?php
/**
 * Field type registry.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

use WPCMB\Abstracts\FieldType;
use WPCMB\Abstracts\Module;
use WPCMB\FieldTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Maps type names to the objects that render, sanitize and format them.
 *
 * Also the seam where field types meet the value pipeline: the load and save
 * filters declared by Values are answered here, so a template read gets a
 * formatted value and a form post gets a sanitized one without either side
 * knowing which class did it.
 */
final class Registry extends Module {

	/**
	 * Type name to handler.
	 *
	 * @var array<string, FieldType>
	 */
	private array $handlers = array();

	/**
	 * Type name to editor label.
	 *
	 * @var array<string, string>
	 */
	private array $labels = array();

	/**
	 * Type name to editor group label.
	 *
	 * @var array<string, string>
	 */
	private array $groups = array();

	/**
	 * Register hooks and the built-in types.
	 */
	public function boot(): void {
		add_filter( 'wpcmb/value/load', array( $this, 'format_value' ), 10, 4 );
		add_filter( 'wpcmb/value/save', array( $this, 'sanitize_value' ), 10, 4 );
		add_filter( 'wpcmb/admin/field_types', array( $this, 'editor_labels' ) );
	}

	/**
	 * Add a field type.
	 *
	 * A later registration for the same type name replaces the earlier one,
	 * which is how a site overrides a built-in type.
	 *
	 * @param FieldType $handler Type handler.
	 */
	public function register( FieldType $handler ): void {
		foreach ( $handler->types() as $type => $label ) {
			$this->handlers[ $type ] = $handler;
			$this->labels[ $type ]   = $label;
			$this->groups[ $type ]   = $handler->group_label();
		}
	}

	/**
	 * The handler for a type name.
	 *
	 * An unknown type falls back to `text` rather than failing: a field whose
	 * type came from a plugin that is no longer active should still render
	 * its stored value instead of taking down the edit screen.
	 *
	 * @param string $type Type name.
	 */
	public function get( string $type ): FieldType {
		$this->load();

		return $this->handlers[ $type ] ?? $this->handlers['text'];
	}

	/**
	 * Whether a type name is registered.
	 *
	 * @param string $type Type name.
	 */
	public function has( string $type ): bool {
		$this->load();

		return isset( $this->handlers[ $type ] );
	}

	/**
	 * Every registered type name, mapped to its label.
	 *
	 * @return array<string, string>
	 */
	public function all(): array {
		$this->load();

		return $this->labels;
	}

	/**
	 * Type labels grouped for the field editor's type dropdown.
	 *
	 * @param array<string, array<string, string>> $types Existing grouped labels.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function editor_labels( $types = array() ): array {
		$this->load();

		$grouped = array();

		foreach ( $this->labels as $type => $label ) {
			$grouped[ $this->groups[ $type ] ][ $type ] = $label;
		}

		return $grouped;
	}

	/**
	 * Which tab a setting belongs on in the field editor.
	 *
	 * Kept here rather than repeated across eleven type classes: the tabs are
	 * a property of the editor, not of the types, and a setting named `max`
	 * means the same thing wherever it appears. A type can still override by
	 * putting a `tab` key in its own schema.
	 */
	private const SETTING_TABS = array(
		'min'                => 'validation',
		'max'                => 'validation',
		'minlength'          => 'validation',
		'maxlength'          => 'validation',
		'pattern'            => 'validation',
		'step'               => 'validation',
		'validation_message' => 'validation',
		'rows'               => 'appearance',
		'layout'             => 'appearance',
		'button_label'       => 'appearance',
		'row_label'          => 'appearance',
		'toggle_label'       => 'appearance',
		'on_label'           => 'appearance',
		'off_label'          => 'appearance',
		'empty_label'        => 'appearance',
		'collapse_after'     => 'appearance',
		'new_lines'          => 'appearance',
		'toolbar'            => 'appearance',
		'tabs'               => 'appearance',
		'media_buttons'      => 'appearance',
		'icon_set'           => 'appearance',
		'return_format'      => 'advanced',
		'decode'             => 'advanced',
		'csv'                => 'advanced',
		'post_type'          => 'advanced',
		'taxonomy'           => 'advanced',
		'role'               => 'advanced',
		'multiple'           => 'advanced',
	);

	/**
	 * Per-type settings the field editor should offer, keyed by type.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function editor_settings(): array {
		$this->load();

		$schemas = array();

		foreach ( $this->handlers as $type => $handler ) {
			$schema = $handler->settings_schema( $type );

			if ( array() === $schema ) {
				continue;
			}

			foreach ( $schema as $name => $setting ) {
				$schema[ $name ]['tab'] = (string) ( $setting['tab'] ?? self::SETTING_TABS[ $name ] ?? 'general' );
			}

			$schemas[ $type ] = $schema;
		}

		return $schemas;
	}

	/**
	 * The Dashicon for every registered type.
	 *
	 * @return array<string, string>
	 */
	public function editor_icons(): array {
		$this->load();

		$icons = array();

		foreach ( $this->handlers as $type => $handler ) {
			$icons[ $type ] = $handler->icon( $type );
		}

		return $icons;
	}

	/**
	 * Format a stored value for reading. Answers `wpcmb/value/load`.
	 *
	 * @param mixed                     $value Stored value.
	 * @param string                    $name  Field name.
	 * @param ObjectRef                 $ref   Object reference.
	 * @param array<string, mixed>|null $field Field definition.
	 *
	 * @return mixed
	 */
	public function format_value( $value, $name = '', $ref = null, $field = null ) {
		if ( ! is_array( $field ) || empty( $field['type'] ) ) {
			return $value;
		}

		return $this->get( (string) $field['type'] )->format( $value, $field );
	}

	/**
	 * Clean a value before storage. Answers `wpcmb/value/save`.
	 *
	 * @param mixed                     $value Submitted value.
	 * @param string                    $name  Field name.
	 * @param ObjectRef                 $ref   Object reference.
	 * @param array<string, mixed>|null $field Field definition.
	 *
	 * @return mixed
	 */
	public function sanitize_value( $value, $name = '', $ref = null, $field = null ) {
		if ( ! is_array( $field ) || empty( $field['type'] ) ) {
			return $value;
		}

		return $this->get( (string) $field['type'] )->sanitize( $value, $field );
	}

	/**
	 * Register the built-in types, once.
	 *
	 * Done lazily rather than in boot() so that a request which never touches
	 * a field never instantiates a type handler, and so the registry is
	 * usable from code that runs before `plugins_loaded`.
	 */
	private function load(): void {
		if ( array() !== $this->handlers ) {
			return;
		}

		foreach ( self::built_in() as $class_name ) {
			$this->register( new $class_name() );
		}

		/**
		 * Fires when field types should be registered.
		 *
		 * Call $registry->register( $handler ) with a WPCMB\Abstracts\FieldType.
		 *
		 * @since 1.0.0
		 *
		 * @param Registry $registry Field type registry.
		 */
		do_action( 'wpcmb/register_field_types', $this );
	}

	/**
	 * The built-in type handlers.
	 *
	 * @return array<int, class-string<FieldType>>
	 */
	private static function built_in(): array {
		return array(
			FieldTypes\Input::class,
			FieldTypes\Textarea::class,
			FieldTypes\Choice::class,
			FieldTypes\Media::class,
			FieldTypes\Relationship::class,
			FieldTypes\Editor::class,
			FieldTypes\Link::class,
			FieldTypes\Repeater::class,
			FieldTypes\Flexible::class,
			FieldTypes\Enhanced::class,
			FieldTypes\Structure::class,
		);
	}
}
