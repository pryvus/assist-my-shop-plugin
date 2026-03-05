<?php

class AMS_Sync_Scheduler {
	private const GROUP = 'assist-my-shop';
	private const CRON_INTERVAL = 'ams_every_minute';
	private const SINGLE_HOOK = 'ams_sync_process_queue';
	private const RECURRING_HOOK = 'ams_sync_process_queue_recurring';
	private const STALE_SINGLE_TIMEOUT_SECONDS = 120;

	public static function register_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = [
				'interval' => 60,
				'display'  => 'Every Minute (AMS)',
			];
		}

		return $schedules;
	}

	public static function schedule_recurring_queue_runner(): void {
		if ( self::use_action_scheduler() ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::RECURRING_HOOK, [], self::GROUP ) ) {
				return;
			}

			if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::RECURRING_HOOK, [], self::GROUP ) ) {
				return;
			}

			if ( function_exists( 'as_schedule_recurring_action' ) ) {
				as_schedule_recurring_action( time() + 60, 60, self::RECURRING_HOOK, [], self::GROUP );
			}

			return;
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SINGLE_HOOK, [], self::GROUP );
			as_unschedule_all_actions( self::RECURRING_HOOK, [], self::GROUP );
		}

		wp_clear_scheduled_hook( self::SINGLE_HOOK );

		if ( ! wp_next_scheduled( self::RECURRING_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::RECURRING_HOOK );
		}
	}

	public static function schedule_single_queue_run( int $delay_seconds = 5 ): void {
		$delay_seconds = max( 0, $delay_seconds );
		self::schedule_recurring_queue_runner();

		if ( self::use_action_scheduler() && function_exists( 'as_schedule_single_action' ) ) {
			self::cleanup_stale_single_actions();

			if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::SINGLE_HOOK, [], self::GROUP ) ) {
				return;
			}

			as_schedule_single_action( time() + $delay_seconds, self::SINGLE_HOOK, [], self::GROUP, true );
			return;
		}

		if ( wp_next_scheduled( self::SINGLE_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + $delay_seconds, self::SINGLE_HOOK );
	}

	public static function clear_all_scheduled_queue_runs(): void {
		if ( self::use_action_scheduler() && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SINGLE_HOOK, [], self::GROUP );
			as_unschedule_all_actions( self::RECURRING_HOOK, [], self::GROUP );
			return;
		}

		wp_clear_scheduled_hook( self::SINGLE_HOOK );
		wp_clear_scheduled_hook( self::RECURRING_HOOK );
	}

	public static function get_single_hook(): string {
		return self::SINGLE_HOOK;
	}

	public static function get_recurring_hook(): string {
		return self::RECURRING_HOOK;
	}

	public static function is_using_action_scheduler(): bool {
		return self::use_action_scheduler();
	}

	private static function cleanup_stale_single_actions(): void {
		if ( ! self::use_action_scheduler() ) {
			return;
		}

		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$next_run = as_next_scheduled_action( self::SINGLE_HOOK, [], self::GROUP );
		if ( ! is_numeric( $next_run ) ) {
			return;
		}

		$stale_before = time() - self::get_stale_single_timeout_seconds();
		if ( (int) $next_run < $stale_before ) {
			as_unschedule_all_actions( self::SINGLE_HOOK, [], self::GROUP );
		}
	}

	private static function get_stale_single_timeout_seconds(): int {
		$value = apply_filters( 'ams_sync_stale_single_timeout_seconds', self::STALE_SINGLE_TIMEOUT_SECONDS );
		return max( 60, (int) $value );
	}

	private static function use_action_scheduler(): bool {
		$enabled = function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_schedule_recurring_action' );

		if ( ! $enabled ) {
			return false;
		}

		return (bool) apply_filters( 'ams_sync_use_action_scheduler', true );
	}
}
