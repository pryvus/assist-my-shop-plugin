<?php
/**
 * Admin General Settings Template
 *
 * This template displays the general settings page for the Woo AI plugin.
 *
 * @package Woo_AI
 *
 * @var string   $enabled               Whether the Assist My Shop is enabled.
 * @var string   $api_url               The SaaS backend API URL.
 * @var string   $api_key               The store's API key.
 * @var array    $available_post_types  Available post types for syncing.
 * @var array    $selected_post_types   Currently selected post types.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$store_status_snapshot = AMS_Api_Messenger::get()->get_store_status_snapshot();
if ( ! empty( $store_status_snapshot['success'] ) && ! empty( $store_status_snapshot['limit_reached'] ) ) :
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Your store has reached its query limit. The chat widget is hidden on the storefront until requests become available again or the plan is upgraded.', 'assist-my-shop' ); ?></p>
	</div>
	<?php
endif;
?>

<form method="post" action="">
    <?php wp_nonce_field( 'ams_save_settings', 'ams_settings_nonce' ); ?>
	<input type="hidden" name="tab" value="general">
	<table class="form-table">
		<tr>
				<th scope="row"><?php esc_html_e( 'Connection Status', 'assist-my-shop' ); ?></th>
				<td>
					<div class="ams-status-card">
						<div class="ams-status-card__header">
							<strong><?php esc_html_e( 'Status', 'assist-my-shop' ); ?></strong>
						</div>
						<div class="ams-status-card__body">
							<span id="ams-connection-indicator" class="ams-connection-indicator"></span>
							<span id="ams-connection-status-text" class="ams-status-card__value"><?php esc_html_e( 'Checking connection...', 'assist-my-shop' ); ?></span>
						</div>
						<div class="ams-status-card__message"><?php esc_html_e( 'Live status of plugin connection to backend API.', 'assist-my-shop' ); ?></div>
					</div>
				</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Current Plan & Limits', 'assist-my-shop' ); ?></th>
			<td>
				<div id="ams-plan-summary" class="ams-plan-summary">
					<div class="ams-plan-summary__header">
						<strong id="ams-plan-name"><?php esc_html_e( 'Loading...', 'assist-my-shop' ); ?></strong>
						<button type="button" id="ams-refresh-store-status" class="ams-plan-summary__refresh">
							<?php esc_html_e( 'Refresh', 'assist-my-shop' ); ?>
						</button>
					</div>
					<div id="ams-plan-status-message" class="ams-plan-summary__message">
						<?php esc_html_e( 'Connect the plugin to load your current plan and limits.', 'assist-my-shop' ); ?>
					</div>
					<div id="ams-plan-usage" class="ams-plan-summary__usage ams-hidden">-</div>
					<div id="ams-plan-progress" class="ams-plan-summary__progress ams-hidden" aria-hidden="true">
						<div id="ams-plan-progress-bar" class="ams-plan-summary__progress-bar"></div>
					</div>
					<div class="ams-plan-summary__hint">
						<?php esc_html_e( 'Usage updates automatically every 5 minutes. Use Refresh to update it immediately after a plan or billing change.', 'assist-my-shop' ); ?>
					</div>
				</div>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Assist My Shop', 'assist-my-shop' ); ?></th>
			<td>
				<input type="checkbox" name="enabled" value="1" <?php checked( $enabled, '1' ); ?> />
				<p class="description"><?php esc_html_e( 'Enable the AI chat widget on your store', 'assist-my-shop' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'API Key', 'assist-my-shop' ); ?></th>
			<td>
				<input type="text" name="api_key" value="<?php echo esc_attr( $api_key ); ?>"
				       class="regular-text" required/>
				<p class="description"><?php esc_html_e( 'Your store\'s API key from the SaaS dashboard', 'assist-my-shop' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Content Types to Sync', 'assist-my-shop' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><span><?php esc_html_e( 'Content Types', 'assist-my-shop' ); ?></span></legend>
					<?php foreach ( $available_post_types as $post_type => $post_type_object ): ?>
						<label>
								<input type="checkbox" name="post_types[]"
								       value="<?php echo esc_attr( $post_type ); ?>"
									<?php checked( in_array( $post_type, $selected_post_types ) ); ?> />
								<?php echo esc_html( $post_type_object->labels->name ); ?>
								<span class="ams-post-type-code">(<?php echo esc_html( $post_type ); ?>)</span>
							</label><br>
						<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Select which content types the AI should have knowledge about. Products are recommended for e-commerce stores.', 'assist-my-shop' ); ?></p>
				</fieldset>
			</td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>

<h2><?php esc_html_e( 'Data Sync Status', 'assist-my-shop' ); ?></h2>
<p>
	<?php
	printf(
		'%s %s',
		esc_html__( 'Last sync:', 'assist-my-shop' ),
		esc_html( get_option( 'ams_last_sync', esc_html__( 'Never', 'assist-my-shop' ) ) )
	);
	?>
</p>
<?php
$ams_batcher = null;
$sync_progress = null;
if ( class_exists( 'AMS_Batcher' ) ) {
	$ams_batcher = new AMS_Batcher();
	$sync_progress = $ams_batcher->get_sync_progress_snapshot();
}

if ( ! $sync_progress ) {
	$sync_progress = get_option( 'ams_sync_progress', null );
}

if ( $sync_progress ) {
	$overall_total = (int) ( $sync_progress['overall_total'] ?? 0 );
	$overall_processed = (int) ( $sync_progress['overall_processed'] ?? 0 );
	$current_total = (int) ( $sync_progress['current_total'] ?? 0 );
	$current_processed = (int) ( $sync_progress['current_processed'] ?? 0 );
	$percent = $overall_total > 0 ? round( ( $overall_processed / $overall_total ) * 100 ) : 0;
	$status = (string) ( $sync_progress['status'] ?? 'in_progress' );
	echo '<div class="notice notice-info inline">';
	if ( $status === 'queued' ) {
		echo '<p><strong>Background sync queued.</strong> Waiting for worker...</p>';
	}
	echo '<p><strong>Background sync in progress:</strong> ' . esc_html( (string) $overall_processed ) . ' of ' . esc_html( (string) $overall_total ) . ' items (' . esc_html( (string) intval( $percent ) ) . '%)</p>';
	if ( ! empty( $sync_progress['current_post_type'] ) ) {
		echo '<p>';
		printf(
			esc_html__( 'Currently syncing: %1$s (%2$d of %3$d)', 'assist-my-shop' ),
			esc_html( $sync_progress['current_post_type'] ),
			$current_processed,
			$current_total
		);
		echo '</p>';
	}
	echo '</div>';
}

$debug_active_job = [];
$debug_queue_items = [];
if ( $ams_batcher instanceof AMS_Batcher ) {
	$debug_active_job = $ams_batcher->get_active_job();
	$debug_queue_items = $ams_batcher->get_queue_items();
}
?>
<p>
	<button type="button" class="button" id="ams-sync-now"><?php esc_html_e( 'Sync Now', 'assist-my-shop' ); ?></button>
		<span id="sync-status" class="ams-sync-status"></span>
	</p>
<?php  if (defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
<h3>Sync Queue Debug</h3>
<p class="description">Raw queue data for debugging.</p>
<p><strong>Active job:</strong></p>
<pre style="max-height:220px; overflow:auto; background:#f6f7f7; border:1px solid #dcdcde; padding:10px;"><?php echo esc_html( wp_json_encode( $debug_active_job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '{}' ); ?></pre>
<p><strong>Queued requests:</strong></p>
<pre style="max-height:260px; overflow:auto; background:#f6f7f7; border:1px solid #dcdcde; padding:10px;"><?php echo esc_html( wp_json_encode( $debug_queue_items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '[]' ); ?></pre>

<div id="ams-sync-progress-container" style="display:none; margin-top:10px; max-width:480px;">
	<div style="background:#eee; border:1px solid #ddd; height:18px; border-radius:3px; overflow:hidden;">
		<div id="ams-sync-progress" style="height:100%; width:0%; background:linear-gradient(90deg,#6bb9f0,#2b9cf3);"></div>
	</div>
</div>
<?php endif; ?>
