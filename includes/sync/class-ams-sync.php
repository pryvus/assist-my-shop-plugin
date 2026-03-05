<?php
class AMS_Sync
{
    public static function init()
    {
        $batcher = new AMS_Batcher();

        $orchestrator = new AMS_Sync_Orchestrator($batcher);
        $admin = new AMS_Sync_Admin($orchestrator);
        $triggers = new AMS_Sync_Triggers($orchestrator);

        $admin->register();
        $triggers->register();
       
        add_filter('cron_schedules', [AMS_Sync_Scheduler::class, 'register_cron_interval']);
        add_action(AMS_Sync_Scheduler::get_single_hook(), [self::class, 'process_queue']);
        add_action(AMS_Sync_Scheduler::get_recurring_hook(), [self::class, 'process_queue']);
        AMS_Sync_Scheduler::schedule_recurring_queue_runner();
    }

    public static function process_queue(): void
    {
        $batcher = new AMS_Batcher();
        $batcher->do_batch();
    }
}
