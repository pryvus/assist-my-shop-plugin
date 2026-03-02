<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API communication layer for Assist My Shop SaaS backend.
 */
class AMS_Api_Messenger {

	use Trait_AMS_Logger;

	private static ?AMS_Api_Messenger $instance = null;

	/**
	 * Base URL for the SaaS API endpoint.
	 *
	 * @since 1.0.0
	 * @var mixed
	 */
	private const API_BASE_URL = 'https://api.assistmyshop.com/api/v1';

	/**
	 * API key for authenticating with the SaaS service.
	 *
	 * @since 1.0.0
	 * @var mixed
	 */
	private mixed $store_api_key;

	/**
	 * Constructor.
	 *
	 * @return void Initializes option-backed properties.
	 */
	public function __construct() {
		$this->define_properties();
	}

	/**
	 * Resolve effective API base URL.
	 *
	 * @return string API base URL.
	 */
	private static function get_base_url(): string {
		return defined( 'AMS_API_BASE_URL' ) ? AMS_API_BASE_URL : self::API_BASE_URL;
	}

	/**
	 * Define class properties from WordPress options.
	 *
	 * Retrieves API URL and API key from the database options.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function define_properties(): void {
		$this->store_api_key = get_option( 'ams_api_key', '' );
	}

	/**
	 * Send JSON request to SaaS API endpoint.
	 *
	 * @param string               $endpoint API endpoint path.
	 * @param array<string, mixed> $data     Request payload.
	 * @return array<string, mixed> API response payload.
	 */
	public function send_to_saas_api( $endpoint, $data ) {
		if ( $data ) {
			$data['api_key'] = $this->store_api_key;
		}

		// Don't log raw data with api_key; use logger which redacts sensitive fields
	
		$this->log( 'Sending request to SaaS API', [ 'endpoint' => $endpoint, 'payload' => $data ], 'debug' );
		

		$response = wp_remote_post( self::get_base_url() . $endpoint, [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'body'    => wp_json_encode( $data ),
			'timeout' => 120,
		] );

		if ( is_wp_error( $response ) ) {
			
			$this->log( 'wp_remote_post error', $response->get_error_message(), 'error' );
			
			return [ 'success' => false, 'error' => $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$this->log( 'SaaS API response', [ 'endpoint' => $endpoint, 'code' => $code, 'body' => $body ], 'debug' );

		// Treat any 2xx as success
		if ( $code >= 200 && $code < 300 ) {
			$decoded = json_decode( $body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return [ 'success' => false, 'error' => 'Invalid JSON response from API' ];
			}
			return $decoded;
		}

		switch ( $code ) {
			case 401:
				return [ 'success' => false, 'error' => 'Unauthorized: Invalid API key' ];
			case 403:
				return [ 'success' => false, 'error' => 'Forbidden: You do not have permission to access this resource' ];
			case 404:
				return [ 'success' => false, 'error' => 'Not Found: Check your API Key' ];
			default:
				return [ 'success' => false, 'error' => 'API error: ' . wp_remote_retrieve_response_message( $response ) ];
		}
	}

	/**
	 * Stream Server-Sent Events response from SaaS API.
	 *
	 * @param string               $endpoint API endpoint path.
	 * @param array<string, mixed> $data     Request payload.
	 * @return void Streams response chunks directly to output.
	 */
	public function stream_from_saas_api( $endpoint, $data ): void {
		if ( $data ) {
			$data['api_key'] = $this->store_api_key;
		}

		// Ensure cURL is available
		if ( ! function_exists( 'curl_init' ) ) {
			// Output SSE friendly error
			header( 'Content-Type: text/event-stream' );
			echo "data: " . json_encode( [ 'error' => 'Server does not support streaming (cURL missing)' ] ) . "\n\n";
			flush();
			return;
		}

		if ( class_exists( 'Trait_AMS_Logger' ) ) {
			Trait_AMS_Logger::log( 'Starting cURL stream to SaaS API', [ 'endpoint' => $endpoint ], 'debug' );
		}

		// Use cURL for streaming response
		$ch = curl_init();

		curl_setopt( $ch, CURLOPT_URL, self::get_base_url() . $endpoint );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode( $data ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Accept: text/event-stream',
		] );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );

		// Stream each chunk as it arrives and forward to client
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function ( $ch, $data ) {
			// Forward the streaming data directly to the client
			echo $data;

			// Flush output to ensure real-time streaming
			if ( ob_get_level() ) {
				ob_flush();
			}
			flush();

			return strlen( $data );
		} );

		// Execute the streaming request
		curl_exec( $ch );

		if ( curl_error( $ch ) ) {
			$this->log( 'cURL streaming error', curl_error( $ch ), 'error' );
			echo "data: " . json_encode( [ 'error' => 'Connection error: ' . curl_error( $ch ) ] ) . "\n\n";
			flush();
		}

		curl_close( $ch );
	}

	/**
	 * Check whether API key is configured.
	 *
	 * @return bool True when API key is present.
	 */
	public function check_api_key(): bool {
		return ! empty( $this->store_api_key );
	}
	
	/**
	 * Validate API credentials and store access.
	 *
	 * @return array<string, mixed> Connection validation result.
	 */
	public function validate_connection(): array {
		if ( empty( $this->check_api_key() ) ) {
			return [
				'success' => false,
				'message' => 'API key is missing or invalid',
			];
		}
		$response = $this->send_to_saas_api('/store/validate', [
			'store_url' => home_url(),
		] );
		if ( $response == null ) {
			return [
				'success' => false,
				'message' => 'No response from API',
			];
		}
		return [
			'success' => $response['success'] ?? false,
			'message' => $response['error'] ?? 'Unknown error',
		];
	}


	/**
	 * Get singleton instance.
	 *
	 * @return AMS_Api_Messenger Shared instance.
	 */
	public static function get(): AMS_Api_Messenger {
		if ( is_null( self::$instance ) && ! ( self::$instance instanceof self ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
