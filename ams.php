<?php
/**
 * Plugin Name: Assist My Shop
 * Plugin URI: https://assistmyshop.com
 * Description: An AI-powered customer support plugin for WooCommerce and WordPress. Provides a chat widget that integrates with your store's data to assist customers in real-time.
 * Version: 1.1.14
 * Author: Pryvus Inc.
 * Author URI: https://pryvus.com
 * License: GPL v2 or later
 * Text Domain: assist-my-shop
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 8.0
 * Tested up to: 6.4
 *
 * @package AMS_WP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AMS_WP_Plugin
 *
 * Main plugin class that handles AI-powered customer support functionality.
 * Manages WooCommerce and WordPress content synchronization with an external
 *
 * @since 1.0.0
 */
class AMS_WP_Plugin {

	/**
	 * Constructor.
	 *
	 * @return void Bootstraps plugin services and hooks.
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->define_constants();
		$this->load_classes();
		// Load plugin translations
		load_plugin_textdomain( 'assist-my-shop', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		// Initialize components
		$this->bootstrap_frontend();
		$this->integrate_chat();
		$this->manage_sync();
		$this->add_admin_pages();

		// Initialize GitHub auto-updater (uses releases from the configured repo)
		// Default repo owner/name can be changed or exposed via admin settings.
		$github_token = get_option( 'ams_github_token', '' );
		new AMS_GitHub_Updater( __FILE__, 'positive-studio/assist-my-shop-plugin', $github_token );



		// Add plugin action link in plugins list to toggle auto-updates
		add_action( 'admin_post_ams_toggle_auto_update', [ $this, 'handle_toggle_auto_update' ] );
	}

	/**
	 * Handle toggle auto-update action.
	 *
	 * @return void Updates plugin auto-update flag and redirects back.
	 */
	public function handle_toggle_auto_update(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'assist-my-shop' ) );
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'ams_toggle_auto_update' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'assist-my-shop' ) );
		}

		$action_param = isset( $_REQUEST['ams_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ams_action'] ) ) : '';
		$action       = $action_param === 'enable' ? 'enable' : 'disable';

		$plugin = plugin_basename( __FILE__ );
		$auto_updates = (array) get_option( 'auto_update_plugins', [] );
		if ( $action === 'enable' ) {
			if ( ! in_array( $plugin, $auto_updates, true ) ) {
				$auto_updates[] = $plugin;
			}
		} else {
			$auto_updates = array_values( array_diff( $auto_updates, [ $plugin ] ) );
		}
		update_option( 'auto_update_plugins', $auto_updates );

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Define plugin path and URL constants.
	 *
	 * @return void Registers constants used across plugin classes.
	 */
	private function define_constants() {
		define( 'AMS_PATH', plugin_dir_path( __FILE__ ) );
		define( 'AMS_URL', plugin_dir_url( __FILE__ ) );
	}

	/**
	 * Initialize admin settings page handlers.
	 *
	 * @return void Boots admin settings class.
	 */
	private function add_admin_pages(): void {
		new AMS_Admin_Settings();
	}


	/**
	 * Bootstrap frontend functionality.
	 *
	 * Enqueues frontend scripts and styles.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	private function bootstrap_frontend(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Set up data synchronization hooks.
	 *
	 * Registers AJAX handlers, WooCommerce integration, post type hooks,
	 * and scheduled sync events.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	private function manage_sync(): void {
		AMS_Sync::init();
	}

	/**
	 * Initialize chat functionality.
	 *
	 * Instantiates the Chat_Manager class to handle chat widget integration.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	private function integrate_chat(): void {
		new AMS_Chat_Manager();
	}

	/**
	 * Register class autoloader.
	 *
	 * Sets up SPL autoloader to automatically load plugin classes, traits and interfaces
	 * from the includes directory following WordPress naming conventions.
	 * Supports nested directory structures.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	private function load_classes(): void {
		// Preload trait files so traits are available when classes reference them
		$basedir = plugin_dir_path( __FILE__ ) . 'includes' . DIRECTORY_SEPARATOR;
		$trait_dir = $basedir . 'traits' . DIRECTORY_SEPARATOR;
		if ( is_dir( $trait_dir ) ) {
			foreach ( glob( $trait_dir . 'trait-*.php' ) as $trait_file ) {
				require_once $trait_file;
			}
		}

		if ( ! function_exists( 'ams_psr0_autoloader' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'psr0.php';
		}
		spl_autoload_register( 'ams_psr0_autoloader' );
	}

	/**
	 * Enqueue frontend chat assets.
	 *
	 * @return void Loads scripts, styles and localized config.
	 */
	public function enqueue_scripts() {
		if ( get_option( 'ams_enabled', '1' ) !== '1' ) {
			return;
		}

		$store_status = AMS_Api_Messenger::get()->get_store_status_snapshot();
		if ( ! empty( $store_status['success'] ) && ! empty( $store_status['limit_reached'] ) ) {
			return;
		}

		$chat_js  = $this->resolve_asset_path( 'assets/chat.js' );
		$chat_css = $this->resolve_asset_path( 'assets/chat.css' );

		// Enqueue bundled DOMPurify (local only).
		wp_register_script(
			'dompurify',
			plugin_dir_url( __FILE__ ) . 'assets/vendor/dompurify.min.js',
			[],
			'2.4.0',
			true
		);
		wp_enqueue_script( 'dompurify' );

		wp_enqueue_script(
			'ams-chat',
			plugin_dir_url( __FILE__ ) . $chat_js,
			[ 'jquery', 'dompurify' ],
			(string) $this->get_asset_version( $chat_js ),
			true
		);

		wp_enqueue_style(
			'ams-chat',
			plugin_dir_url( __FILE__ ) . $chat_css,
			[],
			(string) $this->get_asset_version( $chat_css )
		);

		wp_localize_script( 'ams-chat', 'Ams', $this->get_localize_object() );

		// Add custom CSS variables
		$this->add_custom_styles();
	}

	/**
	 * Inject custom style variables for chat widget.
	 *
	 * @return void Adds CSS variables as inline style.
	 */
	private function add_custom_styles() {
		// Get custom color values and sanitize
		$styles = self::get_style_options();

		$ams_widget_title_color   = sanitize_hex_color( $styles['ams_widget_title_color'] ?? '#ffffff' );
		$primary_gradient_start   = sanitize_hex_color( $styles['primary_gradient_start'] ?? '#667eea' );
		$primary_gradient_end     = sanitize_hex_color( $styles['primary_gradient_end'] ?? '#764ba2' );
		$primary_gradient_color   = sanitize_hex_color( $styles['primary_gradient_color'] ?? '#ffffff' );
		$primary_color            = sanitize_hex_color( $styles['primary_color'] ?? '#764ba2' );
		$primary_hover            = sanitize_hex_color( $styles['primary_hover'] ?? '#6769cb' );
		$secondary_color          = sanitize_hex_color( $styles['secondary_color'] ?? '#6769cb' );
		$text_primary             = sanitize_hex_color( $styles['text_primary'] ?? '#333' );
		$text_secondary           = sanitize_hex_color( $styles['text_secondary'] ?? '#666' );
		$text_light               = sanitize_hex_color( $styles['text_light'] ?? '#999' );
		$background               = sanitize_hex_color( $styles['background'] ?? '#ffffff' );
		$background_light         = sanitize_hex_color( $styles['background_light'] ?? '#f8f9fa' );
		$border_color             = sanitize_hex_color( $styles['border_color'] ?? '#e0e0e0' );
		$border_light             = sanitize_hex_color( $styles['border_light'] ?? '#ddd' );

		$custom_css = ":root {" .
					  "--ams-chat-title-color: " . esc_attr( $ams_widget_title_color ) . ";" .
					  "--ams-primary-gradient-start: " . esc_attr( $primary_gradient_start ) . ";" .
					  "--ams-primary-gradient-end: " . esc_attr( $primary_gradient_end ) . ";" .
					  "--ams-primary-gradient-color: " . esc_attr( $primary_gradient_color ) . ";" .
					  "--ams-primary-color: " . esc_attr( $primary_color ) . ";" .
					  "--ams-primary-hover: " . esc_attr( $primary_hover ) . ";" .
					  "--ams-secondary-color: " . esc_attr( $secondary_color ) . ";" .
					  "--ams-text-primary: " . esc_attr( $text_primary ) . ";" .
					  "--ams-text-secondary: " . esc_attr( $text_secondary ) . ";" .
					  "--ams-text-light: " . esc_attr( $text_light ) . ";" .
					  "--ams-background: " . esc_attr( $background ) . ";" .
					  "--ams-background-light: " . esc_attr( $background_light ) . ";" .
					  "--ams-border-color: " . esc_attr( $border_color ) . ";" .
					  "--ams-border-light: " . esc_attr( $border_light ) . ";" .
					  "}";

		wp_add_inline_style( 'ams-chat', $custom_css );
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
		if ( is_string( $min_path ) && file_exists( plugin_dir_path( __FILE__ ) . $min_path ) ) {
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
		$full_path = plugin_dir_path( __FILE__ ) . $relative_path;
		if ( file_exists( $full_path ) ) {
			return (int) filemtime( $full_path );
		}

		return 1;
	}

	/**
	 * Get currently saved style options.
	 *
	 * @return array<string, string> Style option map.
	 */
	public static function get_style_options(): array {
		return [
			'ams_widget_title_color' 	=> get_option( 'ams_widget_title_color', '#ffffff' ),
			'primary_gradient_start'    => get_option( 'ams_primary_gradient_start', '#667eea' ),
			'primary_gradient_end'      => get_option( 'ams_primary_gradient_end', '#764ba2' ),
			'primary_gradient_color'    => get_option( 'ams_primary_gradient_color', '#ffffff' ),
			'primary_color'             => get_option( 'ams_primary_color', '#764ba2' ),
			'primary_hover'             => get_option( 'ams_primary_hover', '#6769cb' ),
			'secondary_color'           => get_option( 'ams_secondary_color', '#6769cb' ),
			'text_primary'              => get_option( 'ams_text_primary', '#333' ),
			'text_secondary'            => get_option( 'ams_text_secondary', '#666' ),
			'text_light'                => get_option( 'ams_text_light', '#999' ),
			'background'                => get_option( 'ams_background', '#ffffff' ),
			'background_light'          => get_option( 'ams_background_light', '#f8f9fa' ),
			'border_color'              => get_option( 'ams_border_color', '#e0e0e0' ),
			'border_light'              => get_option( 'ams_border_light', '#ddd' ),
		];
	}

	/**
	 * Build localized frontend configuration payload.
	 *
	 * @return array<string, mixed> Localized data structure for JS.
	 */
	private function get_localize_object(): array {
		$currency_code   = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency_code ) : '$';
		$cart_url        = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
		$store_status    = AMS_Api_Messenger::get()->get_store_status_snapshot();

		return [
			'AmsAjax'     => [
				'ajax_url'          => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'ams_chat' ),
				'store_url'         => home_url(),
				'streaming_enabled' => false, // Disabled for OpenAI, only Ollama supports streaming
				'cart_url'          => $cart_url,
				'currency_code'     => $currency_code,
				'currency_symbol'   => $currency_symbol,
				'locale'            => str_replace( '_', '-', get_locale() ),
				'limit_reached'     => ! empty( $store_status['limit_reached'] ),
			],
			'assistantName' => get_option( 'ams_assistant_name', '' ),
		];
	}
}

// Initialize the plugin
new AMS_WP_Plugin();
