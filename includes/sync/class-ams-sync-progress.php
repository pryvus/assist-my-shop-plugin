<?php
/**
 * AMS Sync Progress helper
 *
 * Encapsulates read/write logic for the `ams_sync_progress` and `ams_last_sync` options.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AMS_Sync_Progress {

    private static ?AMS_Sync_Progress $instance = null;

    private function __construct() {
    }

    public static function get(): AMS_Sync_Progress {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Return current progress array or null if none.
     *
     * @return array|null
     */
    public function get_progress(): ?array {
        $progress = get_option( 'ams_sync_progress', null );
        return is_array( $progress ) ? $progress : null;
    }

    /**
     * Update the progress option with provided array.
     *
     * @param array $data
     * @return void
     */
    public function update_progress( array $data ): void {
        update_option( 'ams_sync_progress', $data );
    }

    /**
     * Initialize default progress structure and persist it.
     *
     * @param int $batch_size
     * @return array The initialized progress array.
     */
    public function init_defaults( int $batch_size = 50 ): array {
        $defaults = [
            'step'              => 'start',
            'current_post_type' => null,
            'post_types_queue'  => [],
            'current_processed' => 0,
            'current_total'     => 0,
            'overall_processed' => 0,
            'overall_total'     => 0,
            'batch_size'        => $batch_size,
        ];

        update_option( 'ams_sync_progress', $defaults );

        return $defaults;
    }

    /**
     * Clear progress and optionally set last sync timestamp.
     *
     * @param bool $set_last_sync
     * @return void
     */
    public function clear_progress( bool $set_last_sync = true ): void {
        delete_option( 'ams_sync_progress' );
        if ( $set_last_sync ) {
            update_option( 'ams_last_sync', current_time( 'mysql' ) );
        }
    }

    /**
     * Merge partial updates into the progress array and persist.
     *
     * @param array $patch
     * @return array Updated progress
     */
    public function patch_progress( array $patch ): array {
        $current = $this->get_progress() ?? $this->init_defaults();
        $merged = array_merge( $current, $patch );
        update_option( 'ams_sync_progress', $merged );
        return $merged;
    }

    /**
     * Increment counters for processed items.
     *
     * @param int $count
     * @return array Updated progress
     */
    public function add_processed( int $count ): array {
        $progress = $this->get_progress() ?? $this->init_defaults();
        $progress['current_processed'] = ( $progress['current_processed'] ?? 0 ) + $count;
        $progress['overall_processed'] = ( $progress['overall_processed'] ?? 0 ) + $count;
        update_option( 'ams_sync_progress', $progress );
        return $progress;
    }

}
