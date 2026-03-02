<?php

class AMS_Sync
{
    public static function init()
    {
        $batcher = new AMS_Batcher();

        $orchestrator = new AMS_Sync_Orchestrator( $batcher );
        $admin = new AMS_Sync_Admin( $orchestrator );
        $triggers = new AMS_Sync_Triggers( $orchestrator );

        $admin->register();
        $triggers->register();

        add_filter( 'cron_schedules', [ self::class, 'register_cron_interval' ] );
        add_action( 'ams_sync_process_queue', [ self::class, 'process_queue' ] );
        self::schedule_queue_runner();
    }

    public static function register_cron_interval( array $schedules ): array
    {
        if ( ! isset( $schedules['ams_every_minute'] ) ) {
            $schedules['ams_every_minute'] = [
                'interval' => 60,
                'display'  => 'Every Minute (AMS)',
            ];
        }

        return $schedules;
    }

    public static function process_queue(): void
    {
        $batcher = new AMS_Batcher();
        $batcher->do_batch();
    }

    private static function schedule_queue_runner(): void
    {
        if ( ! wp_next_scheduled( 'ams_sync_process_queue' ) ) {
            wp_schedule_event( time() + 60, 'ams_every_minute', 'ams_sync_process_queue' );
        }
    }
}