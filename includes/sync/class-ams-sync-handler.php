<?php

use JetBrains\PhpStorm\NoReturn;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMS_Sync_Handler {

	private static ?AMS_Sync_Handler $instance = null;
	private AMS_Api_Messenger $api_messenger;
	private AMS_Content_Batcher $content_batcher;
	private AMS_Sync_Scheduler $scheduler;

	public function __construct() {
		$this->define_properties();
		$this->add_hooks();
	}

	private function define_properties(): void {
		$this->api_messenger = AMS_Api_Messenger::get();
		// Content batcher handles data extraction and batching
		if ( ! class_exists( 'AMS_Content_Batcher' ) ) {
			require_once AMS_PATH . '/includes/class-ams-content-batcher.php';
		}
		$this->content_batcher = new AMS_Content_Batcher();

		// Scheduler handles wp_schedule events and queue progression
		if ( ! class_exists( 'AMS_Sync_Scheduler' ) ) {
			require_once AMS_PATH . '/includes/class-ams-sync-scheduler.php';
		}
		$this->scheduler = new AMS_Sync_Scheduler();
	}

	private function add_hooks(): void {
		// Generic post type hooks for data sync
		add_action( 'save_post', [ $this, 'sync_post_update' ], 10, 3 );
		add_action( 'delete_post', [ $this, 'sync_post_delete' ] );
		add_action( 'wp_trash_post', [ $this, 'sync_post_delete' ] );
		// Schedule periodic sync
		add_action( 'wp', [ $this, 'schedule_sync' ] );
		add_action( 'ams_sync_data', [ $this, 'sync_store_data' ] );
		add_action( 'ams_background_sync', [ $this, 'background_sync_store_data' ] );
		add_action( 'wp_ajax_ams_sync_now', [ $this, 'handle_sync_now' ] );
		add_action( 'wp_ajax_ams_get_sync_progress', [ $this, 'get_sync_progress' ] );
	}

	public function sync_post_update( $post_id, $post, $update ): void {
		// Skip if this is an autosave, revision, or not a public post
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || $post->post_status !== 'publish' ) {
			return;
		}

		// Get selected post types to sync
		$selected_post_types = get_option( 'ams_post_types', [ 'product' ] );

		// Only sync if this post type is selected for sync
		if ( ! in_array( $post->post_type, $selected_post_types ) ) {
			return;
		}

		// Skip WooCommerce products as they're handled by WooCommerce hooks
		if ( $post->post_type === 'product' ) {
			return;
		}

		// Trigger a partial sync when post is updated
		wp_schedule_single_event( time() + 60, 'ams_sync_data' );
	}

	public function sync_post_delete( $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Get selected post types to sync
		$selected_post_types = get_option( 'ams_post_types', [ 'product' ] );

		// Only sync if this post type is selected for sync
		if ( ! in_array( $post->post_type, $selected_post_types ) ) {
			return;
		}

		// Send deletion notification to SaaS API
		$this->api_messenger->send_to_saas_api( '/content/delete', [
			'store_url'    => home_url(),
			'content_id'   => $post_id,
			'content_type' => $post->post_type,
		] );
	}

	public function schedule_sync(): void {
		// Delegate scheduling to scheduler helper
		$this->scheduler->schedule_sync();
	}

	/**
	 * Schedule immediate background sync
	 */
	public function schedule_immediate_sync(): void {
		$this->scheduler->schedule_immediate_sync();

		// Ensure background action is registered
		if ( ! has_action( 'ams_background_sync', [ $this, 'background_sync_store_data' ] ) ) {
			add_action( 'ams_background_sync', [ $this, 'background_sync_store_data' ] );
		}
	}

	public function sync_store_data() {
		if ( empty( $this->api_messenger->check_api_key() ) ) {
			if ( class_exists( 'AMS_Logger' ) ) {
				AMS_Logger::log( 'Skipping sync_store_data: missing API key', null, 'warning' );
			}
			return false;
		}

		// Get store info
		$store_info = [
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url(),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'version'  => defined( 'WC_VERSION' ) ? WC_VERSION : '1.0',
		];

		// Get selected post types
		$selected_post_types = get_option( 'ams_post_types', [ 'product' ] );
		$all_content_data    = [];

		foreach ( $selected_post_types as $post_type ) {
			if ( $post_type === 'product' && function_exists( 'wc_get_products' ) ) {
				// Handle WooCommerce products specially
				$content_data = $this->content_batcher->get_products_data();
				if ( ! empty( $content_data ) ) {
					$all_content_data['products'] = $content_data;
				}
			} else {
				// Handle other post types
				$content_data = $this->content_batcher->get_posts_data( $post_type );
				if ( ! empty( $content_data ) ) {
					$all_content_data[ $post_type . 's' ] = $content_data;
				}
			}
		}

		$sync_data = [
			'store_url'  => home_url(),
			'store_info' => $store_info,
		];

		// Add content data to sync payload
		$sync_data = array_merge( $sync_data, $all_content_data );

		$response = $this->api_messenger->send_to_saas_api( '/store/sync', $sync_data );

		if ( class_exists( 'AMS_Logger' ) ) {
			AMS_Logger::log( 'store/sync response', $response, 'debug' );
		}

		if ( $response && $response['success'] ) {
			update_option( 'ams_last_sync', current_time( 'mysql' ) );
			// Clear any in-progress sync state — if a synchronous full sync was
			// performed (or the SaaS responded successfully), the background
			// progress should not remain shown in the admin UI.
			delete_option( 'ams_sync_progress' );
		}

		return $response;
	}



	/**
	 * Complete sync and cleanup
	 */
	private function complete_sync(): void {
		delete_option( 'ams_sync_progress' );
		update_option( 'ams_last_sync', current_time( 'mysql' ) );

		// Log completion
		error_log( 'Ams: Background sync completed successfully' );
	}

	/**
	 * Background sync that processes data in batches
	 */
	public function background_sync_store_data() {
		if ( empty( $this->api_messenger->check_api_key() ) ) {
			return false;
		}

		// Get or initialize sync progress
		$sync_progress = get_option( 'ams_sync_progress', [
			'step'              => 'start',
			'current_post_type' => null,
			'post_types_queue'  => [],
			'current_processed' => 0,
			'current_total'     => 0,
			'overall_processed' => 0,
			'overall_total'     => 0,
			'batch_size'        => 50, // Process 50 items at a time
		] );

		switch ( $sync_progress['step'] ) {
			case 'start':
				$this->sync_store_info();
				$this->init_content_sync( $sync_progress );
				break;

			case 'content':
					$this->sync_content_batch( $sync_progress );
				break;

			case 'orders':
					$orders = $this->content_batcher->get_orders_batch();
					if ( ! empty( $orders ) ) {
						$this->api_messenger->send_to_saas_api( '/store/sync', [
							'store_url' => home_url(),
							'orders'    => $orders,
						] );
					}
				$this->complete_sync();
				break;
		}
	}

	/**
	 * Sync store info (quick, non-blocking)
	 */
	private function sync_store_info() {
		$store_info = [
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url(),
		];

		if ( function_exists( 'WC' ) && function_exists( 'get_woocommerce_currency' ) ) {
			$store_info['version']  = WC()->version;
			$store_info['currency'] = get_woocommerce_currency();
		}

		$this->api_messenger->send_to_saas_api( '/store/sync', [
			'store_url'  => home_url(),
			'store_info' => $store_info,
		] );
	}

	/**
	 * Initialize content sync for all selected post types
	 */
	private function init_content_sync( &$sync_progress ): void {
		$selected_post_types                = get_option( 'ams_post_types', [ 'product' ] );
		$sync_progress['post_types_queue']  = $selected_post_types;
		$sync_progress['step']              = 'content';
		$sync_progress['overall_processed'] = 0;
		$sync_progress['overall_total']     = 0;

		// Calculate total items across all post types
		foreach ( $selected_post_types as $post_type ) {
			if ( $post_type === 'product' && function_exists( 'wc_get_products' ) ) {
				$count                          = wp_count_posts( 'product' );
				$sync_progress['overall_total'] += $count->publish ?? 0;
			} else {
				$count                          = wp_count_posts( $post_type );
				$sync_progress['overall_total'] += $count->publish ?? 0;
			}
		}

		// Start with first post type
		if ( ! empty( $sync_progress['post_types_queue'] ) ) {
			$sync_progress['current_post_type'] = array_shift( $sync_progress['post_types_queue'] );
			// Delegate init of counters to scheduler helper
			$this->scheduler->init_current_post_type_sync( $sync_progress );
		}

		update_option( 'ams_sync_progress', $sync_progress );

		// Schedule next batch
		wp_schedule_single_event( time() + 2, 'ams_background_sync' );
	}

	/**
	 * Initialize sync for current post type
	 */


	/**
	 * Sync content in batches for current post type
	 */
	private function sync_content_batch( &$sync_progress ): void {
		$current_post_type = $sync_progress['current_post_type'];
		$batch_size        = $sync_progress['batch_size'];
		$offset            = $sync_progress['current_processed'];

		if ( $current_post_type === 'product' && function_exists( 'wc_get_products' ) ) {
			// Handle WooCommerce products specially
			$content_data = $this->content_batcher->get_products_data_batch( $batch_size, $offset );
			$data_key     = 'products';
		} else {
			// Handle other post types
			$content_data = $this->content_batcher->get_posts_data_batch( $current_post_type, $batch_size, $offset );
			$data_key     = $current_post_type . 's';
		}

		if ( empty( $content_data ) ) {
			// No more items for current post type
			$this->scheduler->move_to_next_post_type( $sync_progress );

			return;
		}

		// Send batch to SaaS API
		$this->api_messenger->send_to_saas_api( '/store/sync', [
			'store_url' => home_url(),
			$data_key   => $content_data,
		] );

		// Update progress
		$sync_progress['current_processed'] += count( $content_data );
		$sync_progress['overall_processed'] += count( $content_data );
		update_option( 'ams_sync_progress', $sync_progress );

		// Schedule next batch if there are more items for current post type
		if ( $sync_progress['current_processed'] < $sync_progress['current_total'] ) {
			$this->scheduler->schedule_next_batch(3);
		} else {
			// Move to next post type
			$this->scheduler->move_to_next_post_type( $sync_progress );
		}
	}


	#[NoReturn]
	public function handle_sync_now(): void {
		// Verify nonce (AJAX)
		check_ajax_referer( 'ams_sync', 'nonce' );

		// Check user permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
		}

		$result = $this->api_messenger->validate_connection();
		if ( empty( $result ) || ! $result['success'] ) {
			wp_send_json_error( [ 'message' => 'Connection validation failed: ' . ( $result['message'] ?? 'Unknown' ) ], 400 );
		}

		// Schedule the background sync properly
		$this->schedule_immediate_sync();

		// Initialize sync progress so the admin UI can display the scheduled
		// background job status immediately. The actual work will be performed
		// by the scheduled `ams_background_sync` cron job.
		$selected_post_types = get_option( 'ams_post_types', [ 'product' ] );
		$overall_total = 0;
		foreach ( $selected_post_types as $post_type ) {
			if ( $post_type === 'product' && function_exists( 'wc_get_products' ) ) {
				$count = wp_count_posts( 'product' );
				$overall_total += $count->publish ?? 0;
			} else {
				$count = wp_count_posts( $post_type );
				$overall_total += $count->publish ?? 0;
			}
		}

		$sync_progress = [
			'step'              => 'start',
			'current_post_type' => null,
			'post_types_queue'  => $selected_post_types,
			'current_processed' => 0,
			'current_total'     => 0,
			'overall_processed' => 0,
			'overall_total'     => $overall_total,
			'batch_size'        => 50,
		];

		update_option( 'ams_sync_progress', $sync_progress );

		// Background sync scheduled (execution happens via WP-Cron).

		wp_send_json_success( [ 'message' => 'Background sync scheduled successfully' ] );
	}

	/**
	 * Return current sync progress for polling in the admin UI
	 */
	public function get_sync_progress(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
		}

		$sync_progress = get_option( 'ams_sync_progress', null );
		$last_sync = get_option( 'ams_last_sync', 'Never' );

		wp_send_json_success( [ 'progress' => $sync_progress, 'last_sync' => $last_sync ] );
	}

	public static function init(): AMS_Sync_Handler {
		return self::get();
	}

	public static function get(): AMS_Sync_Handler {
		if ( is_null( self::$instance ) && ! ( self::$instance instanceof self ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}