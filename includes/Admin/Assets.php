<?php
/**
 * Admin asset loading.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\FieldTypes\Enhanced;
use WPCMB\Fields\Context;
use WPCMB\Fields\Locations;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Registry;
use WPCMB\Fields\Resolver;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues admin assets, and only on screens that use them.
 *
 * The stylesheet loads on every plugin screen; the builder script loads on
 * the field group editor alone, which is the only screen with anything to
 * script. No shared script bundle exists because nothing else needs one.
 */
final class Assets extends Module {

	/**
	 * Handle for the shared admin bundle.
	 */
	public const HANDLE = 'wpcmb-admin';

	/**
	 * Handle for the field group editor bundle.
	 */
	public const BUILDER_HANDLE = 'wpcmb-builder';

	/**
	 * Handle for the field assets used on edit screens.
	 */
	public const FIELDS_HANDLE = 'wpcmb-fields';

	/**
	 * Handle for the repeater script.
	 */
	public const REPEATER_HANDLE = 'wpcmb-repeater';

	/**
	 * Handle for the QR and barcode encoders.
	 */
	public const CODES_HANDLE = 'wpcmb-codes';

	/**
	 * Handle for the advanced field controls.
	 */
	public const ENHANCED_HANDLE = 'wpcmb-enhanced';

	/**
	 * The cache-busting version for an asset.
	 *
	 * The plugin version alone is not enough: it only changes on release, so
	 * every edit to a script between releases is invisible to a browser that
	 * already cached the old one. Using the file's modification time means a
	 * changed file is always fetched, and an unchanged one is still cached.
	 *
	 * Falls back to the plugin version when the file cannot be read, which is
	 * the right answer for a packaged install where mtimes are meaningless.
	 *
	 * @param string $relative Path below the plugin directory.
	 */
	public static function version( string $relative ): string {
		$path = WPCMB_DIR . ltrim( $relative, '/' );

		if ( ! is_readable( $path ) ) {
			return WPCMB_VERSION;
		}

		$modified = filemtime( $path );

		return false === $modified ? WPCMB_VERSION : WPCMB_VERSION . '.' . $modified;
	}

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Enqueue assets for the current screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		$this->enqueue_fields();

		if ( ! $this->is_plugin_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			WPCMB_URL . 'assets/css/admin.css',
			array(),
			self::version( 'assets/css/admin.css' )
		);

		if ( ! $this->is_editor_screen() ) {
			return;
		}

		wp_enqueue_script(
			self::BUILDER_HANDLE,
			WPCMB_URL . 'assets/js/builder.js',
			array( 'jquery-ui-sortable' ),
			self::version( 'assets/js/builder.js' ),
			true
		);

		wp_localize_script(
			self::BUILDER_HANDLE,
			'wpcmbBuilder',
			array(
				'locationParams'   => Locations::params(),
				'locationChoices'  => Locations::choices(),

				// Parameters whose value is a specific object, searched on
				// demand rather than shipped with the page.
				'locationObjects'  => Locations::object_params(),
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( Ajax::NONCE ),
				/**
				 * Filters the field types offered in the field group editor.
				 *
				 * The registry answers this with every registered type,
				 * grouped by the family it belongs to.
				 *
				 * @since 1.0.0
				 *
				 * @param array<string, array<string, string>> $types Labels keyed by type, grouped.
				 */
				'fieldTypes'       => apply_filters( 'wpcmb/admin/field_types', array() ),
				'fieldSettings'    => $this->container->get( Registry::class )->editor_settings(),
				'fieldIcons'       => $this->container->get( Registry::class )->editor_icons(),

				/**
				 * Filters which field types accept sub fields in the editor.
				 *
				 * A type listed here gets a nested field list in the builder.
				 *
				 * @since 1.0.0
				 *
				 * @param array<int, string> $types Type names.
				 */
				'subFieldTypes'    => (array) apply_filters( 'wpcmb/admin/sub_field_types', array( 'repeater', 'group' ) ),

				/**
				 * Filters which field types are edited as a set of layouts.
				 *
				 * A type listed here gets a layout editor in the builder,
				 * each layout carrying its own nested field list.
				 *
				 * @since 1.0.0
				 *
				 * @param array<int, string> $types Type names.
				 */
				'layoutFieldTypes' => (array) apply_filters( 'wpcmb/admin/layout_field_types', array( 'flexible_content' ) ),
				'i18n'             => array(
					'confirmRemove'       => __( 'Remove this field?', 'wp-custom-meta-box' ),
					'newField'            => __( 'New Field', 'wp-custom-meta-box' ),
					'orLabel'             => __( 'or', 'wp-custom-meta-box' ),
					'andLabel'            => __( 'and', 'wp-custom-meta-box' ),
					'nameRequired'        => __( 'Every field needs a name.', 'wp-custom-meta-box' ),
					'duplicateName'       => __( 'Field names must be unique within a group.', 'wp-custom-meta-box' ),
					'label'               => __( 'Label', 'wp-custom-meta-box' ),
					'name'                => __( 'Name', 'wp-custom-meta-box' ),
					'type'                => __( 'Type', 'wp-custom-meta-box' ),
					'defaultValue'        => __( 'Default value', 'wp-custom-meta-box' ),
					'placeholder'         => __( 'Placeholder', 'wp-custom-meta-box' ),
					'width'               => __( 'Width (%)', 'wp-custom-meta-box' ),
					'instructions'        => __( 'Instructions', 'wp-custom-meta-box' ),
					'required'            => __( 'Required', 'wp-custom-meta-box' ),
					'reorder'             => __( 'Drag to reorder', 'wp-custom-meta-box' ),
					'isEqual'             => __( 'is equal to', 'wp-custom-meta-box' ),
					'isNotEqual'          => __( 'is not equal to', 'wp-custom-meta-box' ),
					'idOrSlug'            => __( 'ID or slug', 'wp-custom-meta-box' ),
					'searching'           => __( 'Searching…', 'wp-custom-meta-box' ),
					'noMatches'           => __( 'No matches. Try a different search.', 'wp-custom-meta-box' ),
					'searchFailed'        => __( 'The search failed. Type an ID instead.', 'wp-custom-meta-box' ),
					'chooseOne'           => __( '— Choose —', 'wp-custom-meta-box' ),
					'typeToSearch'        => __( 'Type to search…', 'wp-custom-meta-box' ),
					'noFieldsYet'         => __( 'No fields yet', 'wp-custom-meta-box' ),
					'noFieldsHint'        => __( 'Add a field to decide what this group collects.', 'wp-custom-meta-box' ),
					'noRulesYet'          => __( 'No location rules', 'wp-custom-meta-box' ),
					'noRulesHint'         => __( 'Without a rule this group stays hidden. Add one to choose where it appears.', 'wp-custom-meta-box' ),
					'tabGeneral'          => __( 'General', 'wp-custom-meta-box' ),
					'tabValidation'       => __( 'Validation', 'wp-custom-meta-box' ),
					'tabAppearance'       => __( 'Appearance', 'wp-custom-meta-box' ),
					'tabLogic'            => __( 'Logic', 'wp-custom-meta-box' ),
					'tabAdvanced'         => __( 'Advanced', 'wp-custom-meta-box' ),
					'wrapperClass'        => __( 'CSS class', 'wp-custom-meta-box' ),
					'wrapperId'           => __( 'CSS id', 'wp-custom-meta-box' ),
					'fieldKey'            => __( 'Field key', 'wp-custom-meta-box' ),
					'duplicateField'      => __( 'Duplicate field', 'wp-custom-meta-box' ),
					'deleteField'         => __( 'Delete field', 'wp-custom-meta-box' ),
					'collapseField'       => __( 'Collapse field', 'wp-custom-meta-box' ),
					'expandField'         => __( 'Expand field', 'wp-custom-meta-box' ),
					'copySuffix'          => __( '(copy)', 'wp-custom-meta-box' ),
					'searchFields'        => __( 'Search fields', 'wp-custom-meta-box' ),
					'expandAll'           => __( 'Expand all', 'wp-custom-meta-box' ),
					'collapseAll'         => __( 'Collapse all', 'wp-custom-meta-box' ),
					/* translators: %d: number of fields. */
					'fieldCount'          => __( '%d fields', 'wp-custom-meta-box' ),
					/* translators: 1: fields shown, 2: fields in total. */
					'fieldCountFiltered'  => __( '%1$d of %2$d fields', 'wp-custom-meta-box' ),
					/* translators: %d: rule group number. */
					'ruleGroup'           => __( 'Rule group %d', 'wp-custom-meta-box' ),
					'duplicateGroup'      => __( 'Duplicate rule group', 'wp-custom-meta-box' ),
					'removeGroup'         => __( 'Remove rule group', 'wp-custom-meta-box' ),
					'removeRule'          => __( 'Remove rule', 'wp-custom-meta-box' ),
					'appearsWhen'         => __( 'Appears when:', 'wp-custom-meta-box' ),
					'appearsNowhere'      => __( 'This group has no rules, so it will not appear anywhere.', 'wp-custom-meta-box' ),
					'anythingLabel'       => __( '(anything)', 'wp-custom-meta-box' ),
					// Matches Locations::describe(), so the sentence in the editor
					// and the one in the field group list are word for word the same.
					'summaryIs'           => __( 'is', 'wp-custom-meta-box' ),
					'summaryIsNot'        => __( 'is not', 'wp-custom-meta-box' ),
					'addRule'             => __( '+ Add rule', 'wp-custom-meta-box' ),
					'settings'            => __( 'Settings', 'wp-custom-meta-box' ),
					'logic'               => __( 'Conditional logic', 'wp-custom-meta-box' ),
					'showThisField'       => __( 'Show this field when', 'wp-custom-meta-box' ),
					'hideThisField'       => __( 'Hide this field when', 'wp-custom-meta-box' ),
					'allRules'            => __( 'all rules match', 'wp-custom-meta-box' ),
					'anyRule'             => __( 'any rule matches', 'wp-custom-meta-box' ),
					'addCondition'        => __( '+ Add condition', 'wp-custom-meta-box' ),
					'noOtherFields'       => __( 'Add another field first to use conditional logic.', 'wp-custom-meta-box' ),
					'noSettings'          => __( 'This field type has no extra settings.', 'wp-custom-meta-box' ),
					'subFields'           => __( 'Sub fields', 'wp-custom-meta-box' ),
					'addSubField'         => __( 'Add sub field', 'wp-custom-meta-box' ),
					'layouts'             => __( 'Layouts', 'wp-custom-meta-box' ),
					'addLayout'           => __( 'Add layout', 'wp-custom-meta-box' ),
					'confirmRemoveLayout' => __( 'Remove this layout? Rows already using it will stop appearing.', 'wp-custom-meta-box' ),
					'icon'                => __( 'Icon', 'wp-custom-meta-box' ),
					'category'            => __( 'Category', 'wp-custom-meta-box' ),
					'maxPerField'         => __( 'Max uses', 'wp-custom-meta-box' ),
					'yes'                 => __( 'Yes', 'wp-custom-meta-box' ),
					'no'                  => __( 'No', 'wp-custom-meta-box' ),
				),
			)
		);

		wp_set_script_translations( self::BUILDER_HANDLE, 'wp-custom-meta-box', WPCMB_DIR . 'languages' );
	}

	/**
	 * Load the field assets, but only on a screen that actually has fields.
	 *
	 * The check costs one cached group lookup and no queries; loading the
	 * media library and the field script on every admin screen would cost
	 * far more, on screens that render nothing.
	 */
	private function enqueue_fields(): void {
		$ref = $this->current_object();

		if ( null === $ref || ! $this->container->get( Resolver::class )->has_groups( new Context( $ref ) ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			self::FIELDS_HANDLE,
			WPCMB_URL . 'assets/css/fields.css',
			array(),
			self::version( 'assets/css/fields.css' )
		);

		wp_enqueue_script(
			self::FIELDS_HANDLE,
			WPCMB_URL . 'assets/js/fields.js',
			array(),
			self::version( 'assets/js/fields.js' ),
			true
		);

		wp_localize_script(
			self::FIELDS_HANDLE,
			'wpcmbFields',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Ajax::NONCE ),
				'dashicons' => Enhanced::dashicons(),
				'i18n'      => array(
					'remove'             => __( 'Remove', 'wp-custom-meta-box' ),
					'selectMedia'        => __( 'Select media', 'wp-custom-meta-box' ),
					'iconDashicons'      => __( 'Dashicons', 'wp-custom-meta-box' ),
					'iconMedia'          => __( 'Media Library', 'wp-custom-meta-box' ),
					'iconUrl'            => __( 'URL', 'wp-custom-meta-box' ),
					'iconUseUrl'         => __( 'Use this URL', 'wp-custom-meta-box' ),
					'embedLoading'       => __( 'Loading the preview…', 'wp-custom-meta-box' ),
					'embedNone'          => __( 'Nothing could be embedded from that URL.', 'wp-custom-meta-box' ),
					'qrTooLong'          => __( 'That is too long to fit in a QR code.', 'wp-custom-meta-box' ),
					'barcodeUnsupported' => __( 'A barcode can only hold plain ASCII characters.', 'wp-custom-meta-box' ),
				),
			)
		);

		// The encoders are separate because they are pure functions with no
		// DOM in them, and because nothing else needs to load them.
		wp_enqueue_script(
			self::CODES_HANDLE,
			WPCMB_URL . 'assets/js/codes.js',
			array(),
			self::version( 'assets/js/codes.js' ),
			true
		);

		wp_enqueue_script(
			self::ENHANCED_HANDLE,
			WPCMB_URL . 'assets/js/enhanced.js',
			array( self::FIELDS_HANDLE, self::CODES_HANDLE ),
			self::version( 'assets/js/enhanced.js' ),
			true
		);

		wp_enqueue_script(
			self::REPEATER_HANDLE,
			WPCMB_URL . 'assets/js/repeater.js',
			array( self::FIELDS_HANDLE, 'jquery-ui-sortable' ),
			self::version( 'assets/js/repeater.js' ),
			true
		);

		wp_localize_script(
			self::REPEATER_HANDLE,
			'wpcmbRepeater',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'i18n'    => array(
					/* translators: %d: row number. Kept as a literal token so the script can substitute it. */
					'row'          => __( 'Row %d', 'wp-custom-meta-box' ),
					'rowFailed'    => __( 'That row could not be added. Try saving and reloading.', 'wp-custom-meta-box' ),
					'importCsv'    => __( 'Import CSV', 'wp-custom-meta-box' ),
					'exportCsv'    => __( 'Export CSV', 'wp-custom-meta-box' ),
					'importFailed' => __( 'That file could not be imported.', 'wp-custom-meta-box' ),
					'importEmpty'  => __( 'That file had no rows.', 'wp-custom-meta-box' ),
					/* translators: %d: number of rows imported. */
					'importDone'   => __( '%d rows added. Save to keep them.', 'wp-custom-meta-box' ),
					'exportFailed' => __( 'That export could not be built.', 'wp-custom-meta-box' ),
				),
			)
		);

		wp_set_script_translations( self::FIELDS_HANDLE, 'wp-custom-meta-box', WPCMB_DIR . 'languages' );
		wp_set_script_translations( self::REPEATER_HANDLE, 'wp-custom-meta-box', WPCMB_DIR . 'languages' );
	}

	/**
	 * The object the current admin screen is editing, if any.
	 */
	private function current_object(): ?ObjectRef {
		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen || FieldGroupPostType::POST_TYPE === $screen->post_type ) {
			return null;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which object the screen shows, not acting on it.
		$id = static fn( string $key ): int => isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return match ( $screen->base ) {
			'post'      => new ObjectRef( ObjectRef::POST, $id( 'post' ) ),
			'term'      => new ObjectRef( ObjectRef::TERM, $id( 'tag_ID' ) ),
			'user-edit' => new ObjectRef( ObjectRef::USER, $id( 'user_id' ) ),
			'profile'   => new ObjectRef( ObjectRef::USER, get_current_user_id() ),
			'comment'   => new ObjectRef( ObjectRef::COMMENT, $id( 'c' ) ),
			default     => null,
		};
	}

	/**
	 * Add a body class so the admin stylesheet can scope itself.
	 *
	 * @param string $classes Existing body classes.
	 */
	public function body_class( $classes ): string {
		$classes = (string) $classes;

		if ( ! $this->is_plugin_screen( '' ) ) {
			return $classes;
		}

		$classes .= ' wpcmb-admin';

		// `auto` adds neither class and lets the stylesheet follow the OS.
		$theme = (string) get_option( 'wpcmb_admin_theme', 'auto' );

		if ( 'dark' === $theme || 'light' === $theme ) {
			$classes .= ' wpcmb-' . $theme;
		}

		return $classes;
	}

	/**
	 * Whether the current screen belongs to this plugin.
	 *
	 * @param string $hook Current admin page hook, empty when unavailable.
	 */
	private function is_plugin_screen( string $hook ): bool {
		if ( str_contains( $hook, '_page_' . Menu::SLUG ) || str_contains( $hook, 'page_wpcmb-' ) ) {
			return true;
		}

		$screen = get_current_screen();

		return $screen instanceof \WP_Screen
			&& ( FieldGroupPostType::POST_TYPE === $screen->post_type || str_starts_with( $screen->id, 'toplevel_page_' . Menu::SLUG ) );
	}

	/**
	 * Whether the current screen is the field group editor.
	 */
	private function is_editor_screen(): bool {
		$screen = get_current_screen();

		return $screen instanceof \WP_Screen
			&& FieldGroupPostType::POST_TYPE === $screen->post_type
			&& 'post' === $screen->base;
	}
}
