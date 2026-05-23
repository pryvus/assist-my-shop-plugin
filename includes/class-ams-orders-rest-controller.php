<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the /orders/lookup endpoint consumed by the SaaS.
 *
 * Authenticates inbound requests via HMAC-SHA256 over the raw body, using the
 * store's `ams_api_key` as the shared secret. Responses are signed with the
 * same scheme so the SaaS PluginClient can verify them.
 */
class AMS_Orders_Rest_Controller {

	use Trait_AMS_Logger;

	private const REST_NAMESPACE = 'woo-ai/v1';
	private const ROUTE          = '/orders/lookup';
	private const MAX_CLOCK_SKEW = 300;
	private const NONCE_TTL      = 300;
	private const ORDERS_LIMIT   = 5;

	/**
	 * Register the REST route on rest_api_init.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the lookup route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_lookup' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle the order-lookup REST request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response Signed response.
	 */
	public function handle_lookup( WP_REST_Request $request ): WP_REST_Response {
		$api_key = (string) get_option( 'ams_api_key', '' );
		if ( $api_key === '' ) {
			return $this->signed_response( [ 'status' => 'unavailable' ], $api_key, 503 );
		}

		$raw_body  = (string) $request->get_body();
		$timestamp = (string) $request->get_header( 'x_woo_ai_timestamp' );
		$nonce     = (string) $request->get_header( 'x_woo_ai_nonce' );
		$signature = (string) $request->get_header( 'x_woo_ai_signature' );

		if ( ! $this->verify_inbound_signature( $raw_body, $timestamp, $nonce, $signature, $api_key ) ) {
			$this->log( 'orders/lookup signature verification failed', [
				'timestamp_present' => $timestamp !== '',
				'nonce_present'     => $nonce !== '',
				'signature_present' => $signature !== '',
			], 'warning' );
			return $this->signed_response( [ 'status' => 'unavailable' ], $api_key, 401 );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return $this->signed_response( [ 'status' => 'unavailable' ], $api_key, 400 );
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return $this->signed_response( [ 'status' => 'unavailable' ], $api_key, 200 );
		}

		$mode = isset( $payload['mode'] ) ? (string) $payload['mode'] : '';

		if ( $mode === 'authenticated' ) {
			$wp_user_id = isset( $payload['wp_user_id'] ) ? (int) $payload['wp_user_id'] : 0;
			if ( $wp_user_id <= 0 ) {
				return $this->signed_response( [ 'status' => 'not_found' ], $api_key, 200 );
			}
			return $this->signed_response( $this->lookup_for_user( $wp_user_id ), $api_key, 200 );
		}

		if ( $mode === 'guest' ) {
			$email        = isset( $payload['customer_email'] ) ? (string) $payload['customer_email'] : '';
			$order_number = isset( $payload['order_number'] ) ? (string) $payload['order_number'] : '';
			if ( $email === '' || $order_number === '' ) {
				return $this->signed_response( [ 'status' => 'not_found' ], $api_key, 200 );
			}
			return $this->signed_response( $this->lookup_for_guest( $email, $order_number ), $api_key, 200 );
		}

		return $this->signed_response( [ 'status' => 'unavailable' ], $api_key, 400 );
	}

	/**
	 * Verify the inbound HMAC signature matches the SaaS-side HmacSigner scheme.
	 */
	private function verify_inbound_signature( string $body, string $timestamp, string $nonce, string $signature, string $api_key ): bool {
		if ( $timestamp === '' || $nonce === '' || $signature === '' ) {
			return false;
		}
		if ( ! ctype_digit( $timestamp ) ) {
			return false;
		}
		if ( abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $api_key );
		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}

		$nonce_cache_key = 'ams_hmac_nonce_' . hash( 'sha256', $nonce . '|' . $api_key );
		if ( get_transient( $nonce_cache_key ) !== false ) {
			return false;
		}
		set_transient( $nonce_cache_key, 1, self::NONCE_TTL );

		return true;
	}

	/**
	 * Emit a signed JSON response and end the request.
	 *
	 * Bypasses WP_REST_Server's own encoding so the bytes on the wire are exactly
	 * the bytes we signed — otherwise WP can re-encode (e.g. with different flags
	 * or additional `_links`) and break the SaaS signature verification.
	 *
	 * @param array<string, mixed> $data
	 */
	private function signed_response( array $data, string $api_key, int $http_status ): WP_REST_Response {
		$body      = (string) wp_json_encode( $data );
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $api_key );

		if ( ! headers_sent() ) {
			status_header( $http_status );
			header( 'Content-Type: application/json' );
			header( 'X-Woo-AI-Timestamp: ' . $timestamp );
			header( 'X-Woo-AI-Nonce: ' . $nonce );
			header( 'X-Woo-AI-Signature: ' . $signature );
		}

		echo $body;
		exit;
	}

	/**
	 * Look up the latest orders for a WordPress user id.
	 *
	 * @return array<string, mixed>
	 */
	private function lookup_for_user( int $wp_user_id ): array {
		$orders = wc_get_orders( [
			'customer_id' => $wp_user_id,
			'limit'       => self::ORDERS_LIMIT,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		if ( ! is_array( $orders ) ) {
			$orders = [];
		}

		$serialized = [];
		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$serialized[] = $this->serialize_order( $order );
			}
		}

		return [
			'status' => 'ok',
			'orders' => $serialized,
		];
	}

	/**
	 * Look up a single order by number for guest mode, gated on email match.
	 *
	 * @return array<string, mixed>
	 */
	private function lookup_for_guest( string $email, string $order_number ): array {
		$normalized_number = ltrim( trim( $order_number ), '#' );
		if ( $normalized_number === '' || ! ctype_digit( $normalized_number ) ) {
			return [ 'status' => 'not_found' ];
		}

		$order = wc_get_order( (int) $normalized_number );
		if ( ! ( $order instanceof WC_Order ) ) {
			return [ 'status' => 'not_found' ];
		}

		$billing_email = (string) $order->get_billing_email();
		if ( strcasecmp( $billing_email, $email ) !== 0 ) {
			return [ 'status' => 'not_found' ];
		}

		return [
			'status' => 'ok',
			'orders' => [ $this->serialize_order( $order ) ],
		];
	}

	/**
	 * Convert a WC_Order into the SaaS-side schema.
	 *
	 * @return array<string, mixed>
	 */
	private function serialize_order( WC_Order $order ): array {
		$line_items = [];
		foreach ( $order->get_items() as $item ) {
			$name     = method_exists( $item, 'get_name' ) ? (string) $item->get_name() : 'item';
			$quantity = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
			$line_items[] = [
				'name'     => $name,
				'quantity' => $quantity,
			];
		}

		$order_date_obj = $order->get_date_created();
		$order_date     = '';
		if ( $order_date_obj instanceof WC_DateTime ) {
			$order_date = $order_date_obj->date( 'Y-m-d' );
		}

		$view_url = '';
		if ( method_exists( $order, 'get_view_order_url' ) ) {
			$view_url = (string) $order->get_view_order_url();
		}

		return [
			'id'           => (int) $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
			'status'       => (string) $order->get_status(),
			'order_date'   => $order_date,
			'total'        => (string) $order->get_total(),
			'currency'     => (string) $order->get_currency(),
			'line_items'   => $line_items,
			'view_url'     => $view_url,
		];
	}
}
