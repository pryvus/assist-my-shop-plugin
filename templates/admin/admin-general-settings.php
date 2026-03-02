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
?>

<form method="post" action="">
    <?php wp_nonce_field( 'ams_save_settings', 'ams_settings_nonce' ); ?>
	<input type="hidden" name="tab" value="general">
	<table class="form-table">
		<tr>
			<th scope="row">Enable Assist My Shop</th>
			<td>
				<input type="checkbox" name="enabled" value="1" <?php checked( $enabled, '1' ); ?> />
				<p class="description">Enable the AI chat widget on your store</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Automatic Plugin Updates</th>
			<td>
				<?php $plugin_basename = plugin_basename( AMS_PATH . 'ams.php' ); ?>
				<input type="checkbox" name="ams_auto_update" value="1" <?php checked( in_array( $plugin_basename, (array) get_option( 'auto_update_plugins', [] ) ), true ); ?> />
				<p class="description">Enable automatic updates for Assist My Shop (uses GitHub Releases).</p>
			</td>
		</tr>
		<tr>
			<th scope="row">API Key</th>
			<td>
				<input type="text" name="api_key" value="<?php echo esc_attr( $api_key ); ?>"
				       class="regular-text" required/>
				<p class="description">Your store's API key from the SaaS dashboard</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Content Types to Sync</th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><span>Content Types</span></legend>
					<?php foreach ( $available_post_types as $post_type => $post_type_object ): ?>
						<label>
							<input type="checkbox" name="post_types[]"
							       value="<?php echo esc_attr( $post_type ); ?>"
								<?php checked( in_array( $post_type, $selected_post_types ) ); ?> />
							<?php echo esc_html( $post_type_object->labels->name ); ?>
							<span style="color: #666; font-size: 12px;">(<?php echo esc_html( $post_type ); ?>)</span>
						</label><br>
					<?php endforeach; ?>
					<p class="description">Select which content types the AI should have knowledge
						about. Products are recommended for e-commerce stores.</p>
				</fieldset>
			</td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>

<h2>Data Sync Status</h2>
<p>Last sync: <?php echo esc_html( get_option( 'ams_last_sync', 'Never' ) ); ?></p>
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
	$percent = $overall_total > 0 ? round( ( $overall_processed / $overall_total ) * 100 ) : 0;
	$status = (string) ( $sync_progress['status'] ?? 'in_progress' );
	echo '<div class="notice notice-info inline">';
	if ( $status === 'queued' ) {
		echo '<p><strong>Background sync queued.</strong> Waiting for worker...</p>';
	}
	echo '<p><strong>Background sync in progress:</strong> ' . esc_html( (string) $overall_processed ) . ' of ' . esc_html( (string) $overall_total ) . ' items (' . esc_html( (string) intval( $percent ) ) . '%)</p>';
	if ( ! empty( $sync_progress['current_post_type'] ) ) {
		echo '<p>Currently syncing: ' . esc_html( $sync_progress['current_post_type'] ) . ' (' . esc_html( (string) intval( $sync_progress['current_processed'] ) ) . ' of ' . esc_html( (string) intval( $sync_progress['current_total'] ) ) . ')</p>';
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
	<button type="button" class="button" id="ams-sync-now">Sync Now</button>
	<span id="sync-status" style="margin-left:10px;"></span>
</p>

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
	<div id="ams-sync-progress-text" style="font-size:12px; color:#333; margin-top:6px;"></div>
</div>