<?php
/**
 * WooCommerce integration.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Integrations;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Context;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Field groups on WooCommerce products, orders and coupons.
 *
 * Products and coupons are post types, so they already work through the
 * normal post-type location rules and post meta. Two things do need code:
 * the product-type and order-status rules the rule builder already offers,
 * and orders under High-Performance Order Storage, which keep their meta in
 * WooCommerce's own tables rather than in `wp_postmeta`.
 *
 * ponytail: variations are addressed as ordinary posts, because that is what
 * they are. Per-variation field UI inside the variations panel is a separate,
 * larger piece of work — the panel renders its rows over AJAX with its own
 * naming scheme — and is not attempted here.
 */
final class WooCommerce extends Module {

	/**
	 * Only load when WooCommerce is active.
	 */
	public function is_enabled(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'wpcmb/object_ref', array( $this, 'order_ref' ), 10, 2 );
		add_filter( 'wpcmb/location/value', array( $this, 'location_value' ), 10, 3 );
		add_filter( 'wpcmb/storage/read', array( $this, 'read_order_meta' ), 10, 3 );
		add_filter( 'wpcmb/storage/write', array( $this, 'write_order_meta' ), 10, 4 );
		add_filter( 'wpcmb/storage/delete', array( $this, 'delete_order_meta' ), 10, 3 );

		add_action( 'woocommerce_product_data_panels', array( $this, 'product_panel' ) );
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_tab' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'order_fields' ) );
	}

	/**
	 * Resolve `order_123` to an object reference.
	 *
	 * @param mixed  $ref        Resolved reference, or null.
	 * @param string $identifier Unresolved identifier.
	 *
	 * @return mixed
	 */
	public function order_ref( $ref, $identifier = '' ) {
		if ( 1 === preg_match( '/^order_(\d+)$/', (string) $identifier, $matches ) ) {
			return new ObjectRef( ObjectRef::POST, (int) $matches[1] );
		}

		return $ref;
	}

	/**
	 * Answer the WooCommerce location rule parameters.
	 *
	 * @param mixed   $value   Parameter value.
	 * @param string  $param   Rule parameter.
	 * @param Context $context Context being described.
	 *
	 * @return mixed
	 */
	public function location_value( $value, $param = '', $context = null ) {
		if ( ! $context instanceof Context || ObjectRef::POST !== $context->ref->type ) {
			return $value;
		}

		$id = (int) $context->ref->id;

		if ( 'wc_product_type' === $param ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;

			return $product ? $product->get_type() : null;
		}

		if ( 'wc_order_status' === $param ) {
			$order = $this->order( $id );

			return $order ? $order->get_status() : null;
		}

		return $value;
	}

	/**
	 * Read an order's meta through WooCommerce.
	 *
	 * @param mixed     $stored Null to read normally.
	 * @param string    $name   Field name.
	 * @param ObjectRef $ref    Object reference.
	 *
	 * @return mixed
	 */
	public function read_order_meta( $stored, $name = '', $ref = null ) {
		$order = $ref instanceof ObjectRef ? $this->order( (int) $ref->id ) : null;

		if ( null === $order ) {
			return $stored;
		}

		$value = $order->get_meta( (string) $name, true );

		// The storage filters treat null as "not claimed", so an order with
		// nothing stored has to answer with something else. An empty string
		// is what get_metadata() would have produced for the same key.
		return '' === $value ? '' : $value;
	}

	/**
	 * Write an order's meta through WooCommerce.
	 *
	 * @param mixed     $claimed Null to write normally.
	 * @param string    $name    Field name.
	 * @param mixed     $value   Value to store.
	 * @param ObjectRef $ref     Object reference.
	 *
	 * @return mixed
	 */
	public function write_order_meta( $claimed, $name = '', $value = null, $ref = null ) {
		$order = $ref instanceof ObjectRef ? $this->order( (int) $ref->id ) : null;

		if ( null === $order ) {
			return $claimed;
		}

		// WooCommerce's CRUD does its own escaping, so the value is passed
		// unslashed — unlike update_metadata(), which unslashes what it gets.
		$order->update_meta_data( (string) $name, $value );
		$order->save();

		return true;
	}

	/**
	 * Delete an order's meta through WooCommerce.
	 *
	 * @param mixed     $claimed Null to delete normally.
	 * @param string    $name    Field name.
	 * @param ObjectRef $ref     Object reference.
	 *
	 * @return mixed
	 */
	public function delete_order_meta( $claimed, $name = '', $ref = null ) {
		$order = $ref instanceof ObjectRef ? $this->order( (int) $ref->id ) : null;

		if ( null === $order ) {
			return $claimed;
		}

		$order->delete_meta_data( (string) $name );
		$order->save();

		return true;
	}

	/**
	 * The order for an id, or null when the id is not an order.
	 *
	 * Under HPOS an order id is not a post id at all, so this is also the
	 * check that decides whether the storage filters should claim a read.
	 *
	 * @param int $id Object id.
	 *
	 * @return \WC_Order|null
	 */
	private function order( int $id ): ?object {
		if ( $id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		// Only claim ids WooCommerce recognises as orders. Products and
		// coupons are posts and keep using post meta, so wc_get_order()
		// returning false for them is exactly the test needed here.
		$order = wc_get_order( $id );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Add a product data tab for matching field groups.
	 *
	 * @param array<string, array<string, mixed>> $tabs Existing tabs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function product_tab( $tabs ): array {
		$tabs = is_array( $tabs ) ? $tabs : array();

		if ( array() === $this->groups_for_current_product() ) {
			return $tabs;
		}

		$tabs['wpcmb'] = array(
			'label'    => __( 'Custom Fields', 'wp-custom-meta-box' ),
			'target'   => 'wpcmb_product_data',
			'class'    => array(),
			'priority' => 80,
		);

		return $tabs;
	}

	/**
	 * Render the product data panel.
	 */
	public function product_panel(): void {
		$groups = $this->groups_for_current_product();

		if ( array() === $groups ) {
			return;
		}

		global $post;

		$ref      = new ObjectRef( ObjectRef::POST, $post instanceof \WP_Post ? $post->ID : 0 );
		$renderer = $this->container->get( Renderer::class );

		echo '<div id="wpcmb_product_data" class="panel woocommerce_options_panel wpcmb-woo-panel">';

		foreach ( $groups as $group ) {
			$renderer->group( $group, $ref );
		}

		echo '</div>';
	}

	/**
	 * Render field groups on the order screen.
	 *
	 * @param mixed $order Order being edited.
	 */
	public function order_fields( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$ref    = new ObjectRef( ObjectRef::POST, (int) $order->get_id() );
		$groups = $this->container->get( Resolver::class )->groups( new Context( $ref ) );

		if ( array() === $groups ) {
			return;
		}

		$renderer = $this->container->get( Renderer::class );

		echo '<div class="wpcmb-woo-order">';

		foreach ( $groups as $group ) {
			printf( '<h3>%s</h3>', esc_html( $group->title ) );
			$renderer->group( $group, $ref );
		}

		echo '</div>';
	}

	/**
	 * Field groups matching the product currently being edited.
	 *
	 * @return array<string, \WPCMB\Fields\FieldGroup>
	 */
	private function groups_for_current_product(): array {
		global $post;

		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return array();
		}

		return $this->container->get( Resolver::class )->groups(
			new Context( new ObjectRef( ObjectRef::POST, $post->ID ) )
		);
	}
}
