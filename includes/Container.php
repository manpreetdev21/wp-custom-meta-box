<?php
/**
 * Service container.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB;

defined( 'ABSPATH' ) || exit;

/**
 * A small service container with lazy factories and shared instances.
 *
 * Deliberately not PSR-11: WordPress plugins gain nothing from the interface
 * and it would add a Composer dependency that must ship in `vendor/`.
 */
final class Container {

	/**
	 * Registered factories, keyed by service id.
	 *
	 * @var array<string, callable(Container): object>
	 */
	private array $factories = array();

	/**
	 * Resolved shared instances, keyed by service id.
	 *
	 * @var array<string, object>
	 */
	private array $resolved = array();

	/**
	 * Register a lazy factory. Resolved once, then shared.
	 *
	 * @param string                      $id      Service id, usually a class name.
	 * @param callable(Container): object $factory Factory invoked on first get().
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Store an already-built instance.
	 *
	 * @param string $id       Service id.
	 * @param object $instance Instance to share.
	 */
	public function share( string $id, object $instance ): void {
		$this->resolved[ $id ] = $instance;
	}

	/**
	 * Whether a service is known to the container.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->resolved[ $id ] ) || isset( $this->factories[ $id ] );
	}

	/**
	 * Resolve a service.
	 *
	 * Unregistered ids are auto-wired: modules receive the container, anything
	 * else is constructed with no arguments. Services needing more than that
	 * register a factory via set().
	 *
	 * @param string $id Service id.
	 *
	 * @throws \InvalidArgumentException When the service cannot be resolved.
	 */
	public function get( string $id ): object {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		if ( isset( $this->factories[ $id ] ) ) {
			$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );

			return $this->resolved[ $id ];
		}

		if ( ! class_exists( $id ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'WPCMB: service "%s" is not registered and is not a class.', esc_html( $id ) )
			);
		}

		$this->resolved[ $id ] = is_subclass_of( $id, Abstracts\Module::class )
			? new $id( $this )
			: new $id();

		return $this->resolved[ $id ];
	}
}
