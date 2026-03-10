<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings controller for plugin configuration pages.
 */
class AMS_Admin_Settings {

    /**
     * Registered styling options and sanitizer callbacks.
     *
     * @var array<int, array{0:string,1:callable|string}>
     */
    private array $global_styling_options = [];

	/**
	 * Constructor.
	 *
	 * @return void Initializes option map and admin hooks.
	 */
	public function __construct() {
        $this->set_global_styling_options();

		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_ams_check_connection', [ $this, 'handle_connection_check' ] );
	}

    /**
     * Enqueue admin scripts and styles.
     *
     * @param string $hook_suffix Current admin page hook.
     * @return void Loads assets for settings and shared admin interactions.
     */
    public function enqueue_admin_assets( string $hook_suffix ): void {
        $media_uploader_js = $this->resolve_asset_path( 'assets/admin/js/media-uploader.js' );
        $ams_admin_js      = $this->resolve_asset_path( 'assets/admin/js/ams-admin.js' );
        $styling_tools_js  = $this->resolve_asset_path( 'assets/admin/js/ams-styling-tools.js' );
        $styling_tools_css = $this->resolve_asset_path( 'assets/admin/css/ams-styling-tools.css' );

        // Enqueue the WordPress media script
        wp_enqueue_media();
        wp_enqueue_script(
            'my-custom-media-upload',
            AMS_URL . $media_uploader_js,
            array( 'jquery' ),
            (string) $this->get_asset_version( $media_uploader_js ),
            true
        );

        // Enqueue admin helper script for sync UI and localize data
        wp_enqueue_script(
            'ams-admin',
            AMS_URL . $ams_admin_js,
            array( 'jquery' ),
            (string) $this->get_asset_version( $ams_admin_js ),
            true
        );

        wp_localize_script( 'ams-admin', 'AmsAdmin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ams_sync' ),
            'i18n'     => [
                'connected'             => __( 'Connected', 'assist-my-shop' ),
                'not_connected'         => __( 'Not connected', 'assist-my-shop' ),
                'checking_connection'   => __( 'Checking connection...', 'assist-my-shop' ),
                'refresh'               => __( 'Refresh', 'assist-my-shop' ),
                'refreshing'            => __( 'Refreshing...', 'assist-my-shop' ),
                'unexpected_response'   => __( 'Unexpected response', 'assist-my-shop' ),
                'request_failed'        => __( 'Request failed', 'assist-my-shop' ),
                'limit_reached'         => __( 'Query limit reached', 'assist-my-shop' ),
                'failed_fetch_progress' => __( 'Failed to fetch progress', 'assist-my-shop' ),
                'sync_in_progress'      => __( 'Sync in progress...', 'assist-my-shop' ),
                'sync_complete'         => __( 'Sync complete — last sync:', 'assist-my-shop' ),
                'error_polling'         => __( 'Error polling sync progress', 'assist-my-shop' ),
                'scheduling_sync'       => __( 'Scheduling background sync...', 'assist-my-shop' ),
                'sync_scheduled'        => __( 'Background sync scheduled — polling progress...', 'assist-my-shop' ),
                'sync_failed'           => __( 'Sync failed:', 'assist-my-shop' ),
                'failed_schedule'       => __( 'Failed to schedule sync', 'assist-my-shop' ),
                'overall'               => __( 'Overall:', 'assist-my-shop' ),
                'of'                    => __( 'of', 'assist-my-shop' ),
                'items'                 => __( 'items', 'assist-my-shop' ),
                'currently_syncing'     => __( 'Currently syncing:', 'assist-my-shop' ),
                'unknown'               => __( 'Unknown', 'assist-my-shop' ),
                'plan_unknown'          => __( 'Unknown plan', 'assist-my-shop' ),
                'request_limit'         => __( 'Request limit', 'assist-my-shop' ),
                'requests_used'         => __( 'Requests used', 'assist-my-shop' ),
                'requests_remaining'    => __( 'Requests remaining', 'assist-my-shop' ),
                'billing_cycle'         => __( 'Billing cycle', 'assist-my-shop' ),
                'not_available'         => __( 'Not available', 'assist-my-shop' ),
                'not_connected_yet'     => __( 'Connect the plugin to load your current plan and limits.', 'assist-my-shop' ),
            ],
        ] );

        if ( 'settings_page_ai-assistant' === $hook_suffix ) {
            wp_enqueue_style(
                'ams-styling-tools',
                AMS_URL . $styling_tools_css,
                [],
                (string) $this->get_asset_version( $styling_tools_css )
            );

            wp_enqueue_script(
                'ams-styling-tools',
                AMS_URL . $styling_tools_js,
                [],
                (string) $this->get_asset_version( $styling_tools_js ),
                true
            );

            wp_localize_script( 'ams-styling-tools', 'AmsStylingTools', [
                'hello_prompt' => __( 'Hello! How can I help you today?', 'assist-my-shop' ),
                'presets'      => $this->get_style_presets(),
            ] );
        }
    }

    /**
     * Resolve asset path to minified variant in production.
     *
     * @param string $relative_path Relative asset path from plugin root.
     * @return string Resolved relative path.
     */
    private function resolve_asset_path( string $relative_path ): string {
        if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
            return $relative_path;
        }

        $min_path = preg_replace( '/\.(js|css)$/', '.min.$1', $relative_path );
        if ( is_string( $min_path ) && file_exists( AMS_PATH . $min_path ) ) {
            return $min_path;
        }

        return $relative_path;
    }

    /**
     * Compute asset version from file modification time.
     *
     * @param string $relative_path Relative asset path from plugin root.
     * @return int Asset version value.
     */
    private function get_asset_version( string $relative_path ): int {
        $full_path = AMS_PATH . $relative_path;
        if ( file_exists( $full_path ) ) {
            return (int) filemtime( $full_path );
        }

        return 1;
    }

	/**
	 * Register admin menu page.
	 *
	 * Adds the Assist My Shop settings page under the WordPress Settings menu.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function add_admin_menu(): void {
		add_submenu_page(
			'options-general.php',
			'Assist My Shop Settings',
			'Assist My Shop',
			'manage_options',
			'ai-assistant',
			[ $this, 'admin_page' ]
		);
	}

	/**
	 * Render the admin settings page.
	 *
	 * Handles form submissions for general settings and styling options,
	 * displays tabbed interface for configuration, and shows sync status.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function admin_page(): void {
        // Handle form submissions with nonce and capability checks
        if ( isset( $_POST['submit'] ) ) {
            // Verify nonce (will die with message on failure)
            check_admin_referer( 'ams_save_settings', 'ams_settings_nonce' );

            // Verify user capability
            if ( ! current_user_can( 'manage_options' ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Insufficient permissions to save settings.', 'assist-my-shop' ) . '</p></div>';
            } else {
                $this->handle_submit( $_POST );
            }
        }

		// Get current tab
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';

		// Get current values
		$api_key             = get_option( 'ams_api_key', '' );
		$enabled             = get_option( 'ams_enabled', '1' );
		$selected_post_types = get_option( 'ams_post_types', [ 'product' ] );

		// Get available post types
		$available_post_types = get_post_types( [ 'public' => true ], 'objects' );
		// Remove attachment from the list as it's not useful for AI
		unset( $available_post_types['attachment'] );

		// Get styling options
		extract(AMS_WP_Plugin::get_style_options());

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Assist My Shop Settings', 'assist-my-shop' ); ?></h1>

			<!-- Tab Navigation -->
			<nav class="nav-tab-wrapper">
				<a href="?page=ai-assistant&tab=general"
				   class="nav-tab <?php echo $current_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'General Settings', 'assist-my-shop' ); ?></a>
				<a href="?page=ai-assistant&tab=styling"
				   class="nav-tab <?php echo $current_tab === 'styling' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Chat Styling', 'assist-my-shop' ); ?></a>
			</nav>

			<?php
            if ( $current_tab === 'general' ) {
			    include AMS_PATH . '/templates/admin/admin-general-settings.php';
            } elseif ( $current_tab === 'styling' ) {
                include AMS_PATH . '/templates/admin/admin-styling-settings.php';
			}
            ?>
		</div>

        <?php
    }

    /**
     * Render media selector field for assistant icon.
     *
     * @return void Outputs HTML for image preview and controls.
     */
    private function output_photo_icon_field() {
        $media_id = get_option( 'ams_photo_icon' );
        $media_url = $media_id ? wp_get_attachment_url( $media_id ) : '';
        ?>
        <input type="hidden" id="ams_photo_icon" name="ams_photo_icon" value="<?php echo esc_attr( $media_id ); ?>" class="custom_media_url" />
        <img id="ams_photo_image_preview"
             src="<?php echo esc_attr( $media_url ); ?>"
             class="ams-photo-image-preview <?php echo $media_id ? '' : 'is-hidden'; ?>"
        />
        <button class="button ams_photo_media_upload"><?php esc_html_e( 'Upload Image', 'assist-my-shop' ); ?></button>
        <button class="button ams_photo_media_remove"
                class="<?php echo $media_id ? '' : 'is-hidden'; ?>"><?php esc_html_e( 'Remove Image', 'assist-my-shop' ); ?></button>
        <?php
    }

    /**
     * Render a text input field for an option-backed setting.
     *
     * @param string $option_name Option name stored in WordPress.
     * @return void Outputs sanitized text input markup.
     */
    private function output_text_field( string $option_name ): void {
        $value = get_option( $option_name, '' );
        ?>
        <input
            type="text"
            name="<?php echo esc_attr( $option_name ); ?>"
            value="<?php echo esc_attr( (string) $value ); ?>"
            class="regular-text"
        />
        <?php
    }

    /**
     * Persist submitted styling options.
     *
     * @param array<string, mixed> $POST Sanitized request payload.
     * @return void Updates style-related options.
     */
    private function update_styling_options( array $POST ): void {
        foreach ( $this->global_styling_options as $option_pair ) {
            $option_name     = $option_pair[0];
            $option_callback = $option_pair[1];
            if ( isset( $POST[ $option_name ] ) ) {
                update_option( $option_name, call_user_func( $option_callback, $POST[ $option_name ] ) );
            }
        }
    }

    /**
     * Persist submitted general settings options.
     *
     * @param array<string, mixed> $POST Request payload.
     * @return void Updates API key, enable flag and synced post types.
     */
    private function update_general_options( array $POST ) {
        update_option( 'ams_api_key', sanitize_text_field( $POST['api_key'] ) );
        update_option( 'ams_enabled', isset( $POST['enabled'] ) ? '1' : '0' );
        // Always use OpenAI (ChatGPT)
        update_option( 'ams_ai_model', 'openai' );

        // Handle post types selection
        $selected_post_types = isset( $POST['post_types'] )
                ? array_map( 'sanitize_text_field', $POST['post_types'] )
                : [];
        update_option( 'ams_post_types', $selected_post_types );
    }

    /**
     * Route settings form submission by active tab.
     *
     * @param array<string, mixed> $POST Request payload.
     * @return void Saves settings and shows admin notice.
     */
    private function handle_submit( array $POST ): void {
        if ( isset( $POST['tab'] ) && $POST['tab'] === 'styling' ) {
            // Handle styling options
            $this->update_styling_options( $POST );

            echo '<div class="notice notice-success"><p>' . esc_html__( 'Styling settings saved!', 'assist-my-shop' ) . '</p></div>';
        } else {
            // Handle general settings
            $this->update_general_options( $POST );

            echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved! Store sync has been scheduled in the background.', 'assist-my-shop' ) . '</p></div>';

        }
    }
    /**
     * Register styling option names and sanitizers.
     *
     * @return void Populates sanitizer map for styling save flow.
     */
    private function set_global_styling_options(): void {
        $this->global_styling_options = [
            [ 'ams_primary_gradient_start', 'sanitize_hex_color' ],
            [ 'ams_primary_gradient_end', 'sanitize_hex_color' ],
            [ 'ams_primary_gradient_color', 'sanitize_hex_color' ],
            [ 'ams_primary_color', 'sanitize_hex_color' ],
            [ 'ams_primary_hover', 'sanitize_hex_color' ],
            [ 'ams_secondary_color', 'sanitize_hex_color' ],
            [ 'ams_text_primary', 'sanitize_hex_color' ],
            [ 'ams_text_secondary', 'sanitize_hex_color' ],
            [ 'ams_text_light', 'sanitize_hex_color' ],
            [ 'ams_background', 'sanitize_hex_color' ],
            [ 'ams_background_light', 'sanitize_hex_color' ],
            [ 'ams_border_color', 'sanitize_hex_color' ],
            [ 'ams_border_light', 'sanitize_hex_color' ],
            [ 'ams_photo_icon', 'sanitize_text_field' ],
            [ 'ams_assistant_name', 'sanitize_text_field' ],
            [ 'ams_chat_title', 'sanitize_text_field' ],
            [ 'ams_widget_title_color', 'sanitize_hex_color' ]
        ];
    }

    /**
     * Return predefined color preset configuration.
     *
     * @return array<string, array<string, string>> Preset map keyed by preset id.
     */
    private function get_style_presets(): array {
        return [
            'light' => [
                'ams_widget_title_color'    => '#ffffff',
                'ams_primary_gradient_start'=> '#667eea',
                'ams_primary_gradient_end'  => '#764ba2',
                'ams_primary_gradient_color'=> '#ffffff',
                'ams_primary_color'         => '#764ba2',
                'ams_primary_hover'         => '#6769cb',
                'ams_secondary_color'       => '#6769cb',
                'ams_text_primary'          => '#333333',
                'ams_text_secondary'        => '#666666',
                'ams_text_light'            => '#999999',
                'ams_background'            => '#ffffff',
                'ams_background_light'      => '#f8f9fa',
                'ams_border_color'          => '#e0e0e0',
                'ams_border_light'          => '#dddddd',
            ],
            'dark' => [
                'ams_widget_title_color'    => '#ffffff',
                'ams_primary_gradient_start'=> '#7b2ff7',
                'ams_primary_gradient_end'  => '#f107a3',
                'ams_primary_gradient_color'=> '#fefcff',
                'ams_primary_color'         => '#b144ff',
                'ams_primary_hover'         => '#cc66ff',
                'ams_secondary_color'       => '#6f59d9',
                'ams_text_primary'          => '#efe9ff',
                'ams_text_secondary'        => '#c2b7dc',
                'ams_text_light'            => '#9788bd',
                'ams_background'            => '#16052f',
                'ams_background_light'      => '#22103d',
                'ams_border_color'          => '#5e3f8f',
                'ams_border_light'          => '#7d61b0',
            ],
            'warm_pastel' => [
                'ams_widget_title_color'    => '#ffffff',
                'ams_primary_gradient_start'=> '#d57a8c',
                'ams_primary_gradient_end'  => '#c9875e',
                'ams_primary_gradient_color'=> '#ffffff',
                'ams_primary_color'         => '#bc6b53',
                'ams_primary_hover'         => '#a95a45',
                'ams_secondary_color'       => '#b8899f',
                'ams_text_primary'          => '#2f2521',
                'ams_text_secondary'        => '#5b4b45',
                'ams_text_light'            => '#8a7a73',
                'ams_background'            => '#fff8f3',
                'ams_background_light'      => '#f7e5db',
                'ams_border_color'          => '#e2c6b8',
                'ams_border_light'          => '#edd8cd',
            ],
            'clean' => [
                'ams_widget_title_color'    => '#ffffff',
                'ams_primary_gradient_start'=> '#1f7ae0',
                'ams_primary_gradient_end'  => '#24b3ff',
                'ams_primary_gradient_color'=> '#ffffff',
                'ams_primary_color'         => '#1889eb',
                'ams_primary_hover'         => '#0f76ce',
                'ams_secondary_color'       => '#23a8f6',
                'ams_text_primary'          => '#1f2937',
                'ams_text_secondary'        => '#4b5563',
                'ams_text_light'            => '#9ca3af',
                'ams_background'            => '#ffffff',
                'ams_background_light'      => '#f4f9ff',
                'ams_border_color'          => '#d5e6f7',
                'ams_border_light'          => '#e7f0fa',
            ],
        ];
    }

    /**
     * Handle AJAX connection validation request.
     *
     * @return void Sends JSON response with connectivity status.
     */
    public function handle_connection_check(): void {
        check_ajax_referer( 'ams_sync', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'assist-my-shop' ) ], 403 );
        }

        $force_refresh = ! empty( $_POST['refresh'] );
        $result = AMS_Api_Messenger::get()->get_store_status_snapshot( $force_refresh );
        wp_send_json_success( [
            'connected' => ! empty( $result['success'] ),
            'message'   => $result['message'] ?? '',
            'limit_reached' => ! empty( $result['limit_reached'] ),
            'store_info' => $result['store_info'] ?? [],
            'plan_info' => $result['plan_info'] ?? [],
        ] );
    }

}
