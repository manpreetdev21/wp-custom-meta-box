<?php
/**
 * Plugin orchestrator.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB;

use WPCMB\Abstracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the container, collects modules and boots them.
 *
 * Singleton because WordPress gives us exactly one plugin lifecycle per
 * request and hooks need a stable callable target. Everything else in the
 * plugin is a plain object resolved from the container.
 */
final class Plugin {

	/**
	 * Sole instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Booted modules, keyed by class name.
	 *
	 * @var array<class-string<Module>, Module>
	 */
	private array $modules = array();

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {
		$this->container = new Container();
		$this->container->share( self::class, $this );
		$this->container->share( Container::class, $this->container );

		// The only services the container cannot auto-wire: they take an
		// argument. Everything else is a Module or has no constructor.
		$this->container->set(
			Fields\Values::class,
			static fn( Container $container ): Fields\Values => new Fields\Values( $container->get( Fields\Repository::class ) )
		);

		$this->container->set(
			Fields\Resolver::class,
			static fn( Container $container ): Fields\Resolver => new Fields\Resolver( $container->get( Fields\Repository::class ) )
		);
	}

	/**
	 * Sole instance accessor.
	 */
	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	/**
	 * Register the boot hooks. Safe to call more than once.
	 */
	public function init(): void {
		add_action( 'plugins_loaded', array( $this, 'boot' ), 5 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Instantiate and boot every registered module.
	 *
	 * @throws \InvalidArgumentException When a filtered module class is invalid.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		/**
		 * Filters the module classes the plugin boots.
		 *
		 * Every entry must extend WPCMB\Abstracts\Module. Add-ons hook here to
		 * register their own modules against the plugin container.
		 *
		 * @since 1.0.0
		 *
		 * @param array<class-string<Module>> $classes   Module class names.
		 * @param Container                   $container Service container.
		 */
		$classes = (array) apply_filters( 'wpcmb/modules', $this->module_classes(), $this->container );

		foreach ( $classes as $class ) {
			if ( ! is_string( $class ) || ! is_subclass_of( $class, Module::class ) ) {
				continue;
			}

			$module = $this->container->get( $class );

			if ( ! $module instanceof Module || ! $module->is_enabled() ) {
				continue;
			}

			$module->boot();
			$this->modules[ $class ] = $module;
		}

		/**
		 * Fires once all modules have booted. The extension point for add-ons.
		 *
		 * @since 1.0.0
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'wpcmb/booted', $this );
	}

	/**
	 * Load translations.
	 *
	 * Hooked to `init` rather than `plugins_loaded` to avoid WordPress 6.7+
	 * just-in-time textdomain notices.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wp-custom-meta-box', false, dirname( WPCMB_BASENAME ) . '/languages' );
	}

	/**
	 * Service container accessor.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * A booted module, or null if it never booted.
	 *
	 * @param string $class_name Module class name.
	 */
	public function module( string $class_name ): ?Module {
		return $this->modules[ $class_name ] ?? null;
	}

	/**
	 * The plugin's own module classes, in boot order.
	 *
	 * Phases 3-12 append to this list.
	 *
	 * @return array<class-string<Module>>
	 */
	private function module_classes(): array {
		return array(
			PostTypes\FieldGroupPostType::class,
			Fields\Registry::class,
			Fields\Renderer::class,
			Database\Revisions::class,
			Admin\Menu::class,
			Admin\Assets::class,
			Admin\FieldGroupList::class,
			Admin\GroupsPage::class,
			Admin\FieldGroupEditor::class,
			Admin\MetaBoxes::class,
			Admin\Ajax::class,
			Admin\SettingsPage::class,
			Admin\ToolsPage::class,
			Admin\DashboardWidget::class,
			Frontend\Form::class,
			Frontend\Submission::class,
			REST\MetaRegistrar::class,
			REST\Controller::class,
			Blocks\BlockRegistry::class,
			Integrations\Shortcodes::class,
			Integrations\WooCommerce::class,
			Integrations\Elementor::class,
			Integrations\Bricks::class,
			CLI\Commands::class,
		);
	}

	/**
	 * Not cloneable.
	 */
	private function __clone() {}

	/**
	 * Not serialisable.
	 *
	 * @throws \LogicException Always.
	 */
	public function __wakeup(): void {
		throw new \LogicException( 'WPCMB\Plugin cannot be unserialized.' );
	}
}
