<?php
/**
 * Admin Styling Settings Template
 *
 * @package Woo_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="">
	<?php wp_nonce_field( 'ams_save_settings', 'ams_settings_nonce' ); ?>
	<input type="hidden" name="tab" value="styling">
	<p><?php esc_html_e( 'Customize the appearance of your AI chat widget to match your brand.', 'assist-my-shop' ); ?></p>
	<h2><?php esc_html_e( 'Chat Widget Styling', 'assist-my-shop' ); ?></h2>

	<table class="form-table">
		<tbody>
		<tr>
			<th><h3><?php esc_html_e( 'Assistant Persona', 'assist-my-shop' ); ?></h3></th>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Assistant Photo Icon', 'assist-my-shop' ); ?></th>
			<td>
				<?php $this->output_photo_icon_field(); ?>
				<p class="description"><?php esc_html_e( 'Set assistant photo icon, square images up to 150x150 would be the best fit.', 'assist-my-shop' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Assistant\'s Name', 'assist-my-shop' ); ?></th>
			<td>
				<?php $this->output_text_field( 'ams_assistant_name' ); ?>
				<p class="description"><?php esc_html_e( 'Set assistant\'s name for the intro chat message.', 'assist-my-shop' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Widget Title', 'assist-my-shop' ); ?></th>
			<td>
				<?php $this->output_text_field( 'ams_chat_title' ); ?>
				<p class="description"><?php esc_html_e( 'Set chat widget title (visible if photo icon is not set)', 'assist-my-shop' ); ?></p>
			</td>
		</tr>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Chat Widget Appearance', 'assist-my-shop' ); ?></h3>
	<p><?php esc_html_e( 'Click an element in the preview, choose a color, then save.', 'assist-my-shop' ); ?></p>

	<div class="ams-styling-layout">
		<div>
			<div id="ams-builder-preview-widget" class="ams-builder-preview-widget">
				<div data-builder-target="header_gradient" class="ams-builder-header">
					<div data-builder-target="header_text" class="ams-builder-header-title"><?php esc_html_e( 'Assist My Shop', 'assist-my-shop' ); ?></div>
					<div class="ams-builder-close">×</div>
				</div>

				<div data-builder-target="widget_background" class="ams-builder-body">
					<div class="ams-builder-row">
						<div data-builder-target="assistant_bubble_bg" class="ams-builder-bubble ams-builder-bubble-assistant">
							<span data-builder-target="assistant_text" class="ams-builder-text-click"><?php esc_html_e( 'Hello! How can I help you today?', 'assist-my-shop' ); ?></span>
						</div>
					</div>
					<div class="ams-builder-row ams-builder-row-user">
						<div data-builder-target="user_bubble_bg" class="ams-builder-bubble ams-builder-bubble-user">
							<span data-builder-target="user_text" class="ams-builder-text-click"><?php esc_html_e( 'I need black sneakers', 'assist-my-shop' ); ?></span>
						</div>
					</div>
					<div class="ams-builder-row">
						<div data-builder-target="assistant_bubble_bg" class="ams-builder-bubble ams-builder-bubble-assistant ams-builder-bubble-wide">
							<span data-builder-target="assistant_text" class="ams-builder-text-click"><?php esc_html_e( 'Great choice! Here is one option:', 'assist-my-shop' ); ?></span>
							<div data-builder-target="card_border" class="ams-builder-card">
								<div class="ams-builder-card-row">
									<div
										data-builder-target="card_thumb_bg"
										class="ams-builder-thumb"
										title="<?php echo esc_attr__( 'Product Thumbnail Placeholder', 'assist-my-shop' ); ?>"
									></div>
									<div class="ams-builder-card-info">
										<div data-builder-target="assistant_text" class="ams-builder-card-title"><?php esc_html_e( 'Running Shoe Pro', 'assist-my-shop' ); ?></div>
										<div data-builder-target="price_text" class="ams-builder-card-price">$89.99</div>
										<button type="button" data-builder-target="button_bg" class="ams-builder-card-button"><?php esc_html_e( 'View Product', 'assist-my-shop' ); ?></button>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div data-builder-target="meta_text" class="ams-builder-meta"><?php esc_html_e( '04:35 PM', 'assist-my-shop' ); ?></div>
				</div>

				<div data-builder-target="input_area_bg" class="ams-builder-input-area">
					<input type="text" value="<?php echo esc_attr__( 'Ask me...', 'assist-my-shop' ); ?>" class="ams-builder-input" readonly>
					<button type="button" data-builder-target="button_bg" class="ams-builder-send"><?php esc_html_e( 'Send', 'assist-my-shop' ); ?></button>
				</div>
			</div>
		</div>

		<div>
			<div class="postbox ams-styling-postbox">
				<h3 class="ams-mt-0"><?php esc_html_e( 'Element Color Editor', 'assist-my-shop' ); ?></h3>
				<p class="ams-mt-0"><?php esc_html_e( 'Selected:', 'assist-my-shop' ); ?> <strong id="ams-builder-selected-label"><?php esc_html_e( 'None', 'assist-my-shop' ); ?></strong></p>
				<div id="ams-builder-controls"></div>
			</div>

			<div class="postbox ams-styling-postbox ams-styling-postbox-spaced">
				<h3 class="ams-mt-0"><?php esc_html_e( 'Presets', 'assist-my-shop' ); ?></h3>
				<div class="ams-preset-cards">
					<button type="button" class="ams-preset-card" data-preset="light">
						<strong><?php esc_html_e( 'Light', 'assist-my-shop' ); ?></strong>
						<div class="ams-preset-mini">
							<div class="ams-preset-mini-header ams-preset-mini-header--light"></div>
							<div class="ams-preset-mini-body ams-preset-mini-body--light">
								<div class="ams-preset-mini-bubble assistant ams-preset-mini-bubble--light-assistant"></div>
								<div class="ams-preset-mini-bubble user ams-preset-mini-bubble--light-user"></div>
							</div>
						</div>
					</button>
					<button type="button" class="ams-preset-card" data-preset="dark">
						<strong><?php esc_html_e( 'Dark', 'assist-my-shop' ); ?></strong>
						<div class="ams-preset-mini">
							<div class="ams-preset-mini-header ams-preset-mini-header--dark"></div>
							<div class="ams-preset-mini-body ams-preset-mini-body--dark">
								<div class="ams-preset-mini-bubble assistant ams-preset-mini-bubble--dark-assistant"></div>
								<div class="ams-preset-mini-bubble user ams-preset-mini-bubble--dark-user"></div>
							</div>
						</div>
					</button>
					<button type="button" class="ams-preset-card" data-preset="warm_pastel">
						<strong><?php esc_html_e( 'Warm Pastel', 'assist-my-shop' ); ?></strong>
						<div class="ams-preset-mini">
							<div class="ams-preset-mini-header ams-preset-mini-header--warm-pastel"></div>
							<div class="ams-preset-mini-body ams-preset-mini-body--warm-pastel">
								<div class="ams-preset-mini-bubble assistant ams-preset-mini-bubble--warm-pastel-assistant"></div>
								<div class="ams-preset-mini-bubble user ams-preset-mini-bubble--warm-pastel-user"></div>
							</div>
						</div>
					</button>
					<button type="button" class="ams-preset-card" data-preset="clean">
						<strong><?php esc_html_e( 'Clean', 'assist-my-shop' ); ?></strong>
						<div class="ams-preset-mini">
							<div class="ams-preset-mini-header ams-preset-mini-header--clean"></div>
							<div class="ams-preset-mini-body ams-preset-mini-body--clean">
								<div class="ams-preset-mini-bubble assistant ams-preset-mini-bubble--clean-assistant"></div>
								<div class="ams-preset-mini-bubble user ams-preset-mini-bubble--clean-user"></div>
							</div>
						</div>
					</button>
				</div>
			</div>

			<?php
			$hidden_colors = [
				'ams_widget_title_color'     => $ams_widget_title_color,
				'ams_primary_gradient_start' => $primary_gradient_start,
				'ams_primary_gradient_end'   => $primary_gradient_end,
				'ams_primary_gradient_color' => $primary_gradient_color,
				'ams_primary_color'          => $primary_color,
				'ams_primary_hover'          => $primary_hover,
				'ams_secondary_color'        => $secondary_color,
				'ams_text_primary'           => $text_primary,
				'ams_text_secondary'         => $text_secondary,
				'ams_text_light'             => $text_light,
				'ams_background'             => $background,
				'ams_background_light'       => $background_light,
				'ams_border_color'           => $border_color,
				'ams_border_light'           => $border_light,
			];
			foreach ( $hidden_colors as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
		</div>
	</div>

	<?php submit_button( esc_html__( 'Save Styling Settings', 'assist-my-shop' ) ); ?>
</form>
