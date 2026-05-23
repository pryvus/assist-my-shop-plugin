<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend chat widget renderer and AJAX handlers.
 */
class AMS_Chat_Manager {
    /**
     * API messenger service instance.
     *
     * @var AMS_Api_Messenger|null
     */
    private ?AMS_Api_Messenger $api_messenger;
    
	/**
	 * Constructor.
	 *
	 * @return void Initializes messenger and registers hooks.
	 */
	public function __construct() {
        $this->api_messenger = AMS_Api_Messenger::get();
        
		add_action( 'wp_footer', [ $this, 'add_chat_widget' ] );
		add_action( 'wp_ajax_ams_chat', [ $this, 'handle_chat_request' ] );
		add_action( 'wp_ajax_nopriv_ams_chat', [ $this, 'handle_chat_request' ] );
		add_action( 'wp_ajax_ams_chat_stream', [ $this, 'handle_chat_stream' ] );
		add_action( 'wp_ajax_nopriv_ams_chat_stream', [ $this, 'handle_chat_stream' ] );
		add_action( 'wp_ajax_ams_history', [ $this, 'handle_chat_history' ] );
		add_action( 'wp_ajax_nopriv_ams_history', [ $this, 'handle_chat_history' ] );
	}

	/**
	 * Render chat widget markup in footer.
	 *
	 * @return void Outputs widget HTML when plugin is enabled.
	 */
	public function add_chat_widget() {
		if ( get_option( 'ams_enabled', '1' ) !== '1' ) {
			return;
		}

		?>
		<div id="ams-chat-widget" style="display: none;">
			<div id="ams-chat-toggle">
				<span>💬</span>
			</div>
			<div id="ams-chat-container">
                <div id="ams-chat-header">
                    <div class="ams-chat-persona">
                        <?php
                        $media_id  = get_option( 'ams_photo_icon' );
                        $media_url = $media_id ? wp_get_attachment_image_src( $media_id, 'thumbnail' ) : '';
                        if ( $media_url && ! empty( $media_url[0] ) ): ?>
                            <img src="<?php echo esc_url( $media_url[0] ); ?>" class="ams-chat-photo">
                        <?php endif; ?>
                        <div class="ams-chat-meta">
						    <h4 class="ams-chat-title"><?php echo esc_html( get_option( 'ams_chat_title', 'AI Assistant' ) ); ?></h4>
                            <div class="ams-chat-status">
                                <span class="ams-chat-status-dot"></span>
                                <span class="ams-chat-status-text"><?php esc_html_e( 'Online', 'assist-my-shop' ); ?></span>
                            </div>
                        </div>
                    </div>
                    <button id="ams-chat-close">&times;</button>
                </div>
				<div id="ams-chat-messages"></div>
				<div id="ams-chat-input-container">
					<input type="text" id="ams-chat-input" placeholder="<?php echo esc_attr__( 'Ask me...', 'assist-my-shop' ); ?>"/>
					<button id="ams-chat-send"><?php esc_html_e( 'Send', 'assist-my-shop' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle non-streaming chat AJAX request.
	 *
	 * @return void Sends JSON response to client.
	 */
	public function handle_chat_request() {
		check_ajax_referer( 'ams_chat', 'nonce' );

		$message    = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $message ) ) {
			wp_send_json_error( [ 'error' => __( 'Message is required', 'assist-my-shop' ) ], 400 );
		}

		$payload = [
			'store_url'  => home_url(),
			'message'    => $message,
			'session_id' => $session_id,
			'ai_model'   => 'openai',
		];

		$customer_token = $this->maybe_mint_customer_token();
		if ( $customer_token !== '' ) {
			$payload['customer_token'] = $customer_token;
		}

		$lookup_form_response = $this->sanitize_lookup_form_response();
		if ( $lookup_form_response !== null ) {
			$payload['lookup_form_response'] = $lookup_form_response;
		}

		$response = $this->api_messenger->send_to_saas_api( '/chat', $payload );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'error' => $response->get_error_message() ], 500 );
		}

		wp_send_json( $response );
	}

	/**
	 * Handle streaming chat AJAX request.
	 *
	 * @return void Streams SSE response to client.
	 */
	public function handle_chat_stream() {
		check_ajax_referer( 'ams_chat', 'nonce' );

		$message    = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $message ) ) {
			header( 'Content-Type: text/event-stream' );
			echo "data: " . wp_json_encode( [ 'error' => __( 'Message is required', 'assist-my-shop' ) ] ) . "\n\n";
			wp_die();
		}

		// Set headers for Server-Sent Events
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Headers: Cache-Control' );

		// Disable output buffering for real-time streaming
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		$payload = [
			'store_url'  => home_url(),
			'message'    => $message,
			'session_id' => $session_id,
			'ai_model'   => 'openai',
		];

		$customer_token = $this->maybe_mint_customer_token();
		if ( $customer_token !== '' ) {
			$payload['customer_token'] = $customer_token;
		}

		$lookup_form_response = $this->sanitize_lookup_form_response();
		if ( $lookup_form_response !== null ) {
			$payload['lookup_form_response'] = $lookup_form_response;
		}

		// Stream the response from SaaS API (stream handler will echo/flush)
		$this->api_messenger->stream_from_saas_api( '/chat/stream', $payload );

		wp_die();
	}

	/**
	 * Mint a signed customer token for the current WordPress user when possible.
	 *
	 * Requires a logged-in WP user, a stored SaaS api key, and a cached SaaS-side
	 * store id (populated by AMS_Api_Messenger::validate_connection()). Returns an
	 * empty string when any prerequisite is missing — the SaaS will then fall back
	 * to its guest order-lookup flow.
	 *
	 * Token format mirrors App\Services\Security\CustomerTokenVerifier:
	 *   base64url(json header) . base64url(json body) . base64url(HMAC_SHA256(h.b, api_key))
	 *
	 * @return string Compact token or empty string when unavailable.
	 */
	private function maybe_mint_customer_token(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$wp_user_id = (int) get_current_user_id();
		if ( $wp_user_id <= 0 ) {
			return '';
		}

		$api_key  = (string) get_option( 'ams_api_key', '' );
		$store_id = (int) get_option( 'ams_store_id', 0 );
		if ( $api_key === '' || $store_id <= 0 ) {
			return '';
		}

		$header = [ 'alg' => 'HS256', 'typ' => 'AMSCT' ];
		$body   = [
			'wp_user_id' => $wp_user_id,
			'store_id'   => $store_id,
			'exp'        => time() + 10 * MINUTE_IN_SECONDS,
		];

		$header_b64 = self::b64u_encode( (string) wp_json_encode( $header ) );
		$body_b64   = self::b64u_encode( (string) wp_json_encode( $body ) );
		$signature  = hash_hmac( 'sha256', $header_b64 . '.' . $body_b64, $api_key, true );
		$sig_b64    = self::b64u_encode( $signature );

		return $header_b64 . '.' . $body_b64 . '.' . $sig_b64;
	}

	/**
	 * Base64url-encode a binary string (no padding, '+/' → '-_').
	 *
	 * @param string $data Raw bytes to encode.
	 * @return string Base64url-encoded value.
	 */
	private static function b64u_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Extract and sanitize an inline-form submission from $_POST.
	 *
	 * Expected shape: lookup_form_response[form_id]=string &
	 * lookup_form_response[values][name1]=value1 ...
	 *
	 * @return array{form_id: string, values: array<string, string>}|null
	 */
	private function sanitize_lookup_form_response(): ?array {
		if ( ! isset( $_POST['lookup_form_response'] ) || ! is_array( $_POST['lookup_form_response'] ) ) {
			return null;
		}
		$raw = wp_unslash( $_POST['lookup_form_response'] );
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$form_id = isset( $raw['form_id'] ) ? sanitize_text_field( (string) $raw['form_id'] ) : '';
		if ( $form_id === '' ) {
			return null;
		}

		$values = [];
		if ( isset( $raw['values'] ) && is_array( $raw['values'] ) ) {
			foreach ( $raw['values'] as $key => $value ) {
				if ( ! is_scalar( $key ) || ! is_scalar( $value ) ) {
					continue;
				}
				$safe_key = sanitize_key( (string) $key );
				if ( $safe_key === '' ) {
					continue;
				}
				$values[ $safe_key ] = sanitize_text_field( (string) $value );
			}
		}

		return [
			'form_id' => $form_id,
			'values'  => $values,
		];
	}

	/**
	 * Handle chat history AJAX request.
	 *
	 * @return void Sends JSON response with history data.
	 */
	public function handle_chat_history() {
		check_ajax_referer( 'ams_chat', 'nonce' );

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $session_id ) ) {
			wp_send_json_error( [ 'error' => __( 'Session ID is required', 'assist-my-shop' ) ], 400 );
		}

		$response = $this->api_messenger->send_to_saas_api( '/chat/history', [
			'store_url'  => home_url(),
			'session_id' => $session_id,
		] );

		// Ensure we always have a valid response
		if ( ! $response || ! is_array( $response ) ) {
			wp_send_json_error( [ 'error' => __( 'Failed to retrieve chat history', 'assist-my-shop' ) ], 500 );
		}

		wp_send_json( $response );
	}
}
