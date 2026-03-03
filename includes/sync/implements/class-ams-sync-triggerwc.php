<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMS_Sync_TriggerWC {
	use Trait_AMS_Sync_Enabled_Types;

	private AMS_Sync_Orchestrator $orchestrator;

	public function __construct( AMS_Sync_Orchestrator $orchestrator ) {
		$this->orchestrator = $orchestrator;
		$this->init_enabled_types();
	}

	public function register(): void {
		if ( $this->is_type_enabled( 'order' ) ) {
			add_action( 'woocommerce_new_order', [ $this, 'on_new_order' ] );
		}

		if ( $this->is_type_enabled( 'product' ) ) {
			add_action( 'woocommerce_update_product', [ $this, 'on_product_change' ] );
		}
	}

	public function on_new_order( int $order_id ): void {
		if ( ! $this->is_type_enabled( 'order' ) ) {
			return;
		}

		$request = new AMS_Sync_Request( 'delta', [ 'order' ], [ $order_id ], 'new_order' );
		$this->orchestrator->run( $request );
	}

	public function on_product_change( int $product_id ): void {
		if ( ! $this->is_type_enabled( 'product' ) ) {
			return;
		}

		$request = new AMS_Sync_Request( 'delta', [ 'product' ], [ $product_id ], 'product_update' );
		$this->orchestrator->run( $request );
	}

}