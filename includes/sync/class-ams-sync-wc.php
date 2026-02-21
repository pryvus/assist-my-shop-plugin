<?php
/**
 * WooCommerce Sync Class
 *
 * Handles synchronization of WooCommerce data with external AI service.
 * Listens for WooCommerce events and schedules sync operations.
 *
 * @package AMS_WP
 * @since   1.0.0
 */

/**
 * Class Woocommerce_Sync
 *
 * Provides functionality to synchronize WooCommerce orders and products
 * with an external service by hooking into WooCommerce actions and
 * scheduling background sync events.
 *
 * @since 1.0.0
 */
class AMS_Sync_WC {

	/**
	 * Initialize WooCommerce synchronization hooks.
	 *
	 * Registers action hooks for WooCommerce events including new orders
	 * and product updates to trigger data synchronization.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function init() {
		// WooCommerce hooks for data sync
		add_action( 'woocommerce_new_order', [ self::class, 'sync_new_order' ] );
		add_action( 'woocommerce_product_set_stock', [ self::class, 'sync_product_update' ] );
		add_action( 'woocommerce_update_product', [ self::class, 'sync_product_update' ] );
	}

	/**
	 * Trigger a partial sync when a new order is created.
	 *
	 * Schedules a single sync event to run 60 seconds after order creation.
	 * This delay helps batch multiple rapid changes into a single sync operation.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function sync_new_order() {
		wp_schedule_single_event( time() + 60, 'ams_sync_data' );
	}

	/**
	 * Trigger a partial sync when a product is updated.
	 *
	 * Schedules a single sync event to run 60 seconds after product modification.
	 * Handles both stock changes and general product updates.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function sync_product_update() {
		wp_schedule_single_event( time() + 60, 'ams_sync_data' );
	}
}