<?php
/**
 * AMS Sync Scheduler
 *
 * Encapsulates scheduling and queue progression logic for background sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AMS_Sync_Scheduler {

    public function __construct() {
    }

    /**
     * Schedule an immediate background sync.
     */
    public function schedule_immediate_sync(): void {
        // Clear any existing sync jobs first
        wp_clear_scheduled_hook( 'ams_background_sync' );

        // Schedule immediate sync
        wp_schedule_single_event( time() + 5, 'ams_background_sync' );
    }

    /**
     * Ensure periodic schedule exists (no-op placeholder for now).
     */
    public function schedule_sync(): void {
        if ( ! wp_next_scheduled( 'ams_sync_data' ) ) {
            // Placeholder: could schedule recurring events here
        }
    }

    /**
     * Schedule next batch run after given delay in seconds.
     */
    public function schedule_next_batch( int $delay = 2 ): void {
        wp_schedule_single_event( time() + $delay, 'ams_background_sync' );
    }

    /**
     * Initialize sync counters for the current post type.
     */
    public function init_current_post_type_sync( array &$sync_progress ): void {
        $post_type = $sync_progress['current_post_type'];
        $count = wp_count_posts( $post_type );
        $sync_progress['current_total']     = $count->publish ?? 0;
        $sync_progress['current_processed'] = 0;
    }

    /**
     * Move to next post type or mark step as orders and schedule next batch.
     */
    public function move_to_next_post_type( array &$sync_progress ): void {
        if ( ! empty( $sync_progress['post_types_queue'] ) ) {
            // Move to next post type
            $sync_progress['current_post_type'] = array_shift( $sync_progress['post_types_queue'] );
            $this->init_current_post_type_sync( $sync_progress );
            update_option( 'ams_sync_progress', $sync_progress );
            $this->schedule_next_batch(2);
        } else {
            // All post types done, move to orders
            $sync_progress['step'] = 'orders';
            update_option( 'ams_sync_progress', $sync_progress );
            $this->schedule_next_batch(2);
        }
    }

}
