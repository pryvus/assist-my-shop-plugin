<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMS_Sync_Admin {
	private AMS_Sync_Orchestrator $orchestrator;

	public function __construct( AMS_Sync_Orchestrator $orchestrator ) {
		$this->orchestrator = $orchestrator;
	}

	public function register(): void {
		add_action( 'wp_ajax_ams_sync_now', [ $this, 'sync_now' ] );
		add_action( 'wp_ajax_ams_get_sync_progress', [ $this, 'get_sync_progress' ] );
	}

	public function sync_now(): void {
		check_ajax_referer( 'ams_sync', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
		}

		$types = get_option( 'ams_post_types', [] );
		$request = new AMS_Sync_Request( 'full', $types, [], 'admin' );
		$result = $this->orchestrator->run( $request );
		$status = (string) ( $result['status'] ?? '' );

		if ( ! empty( $result['success'] ) ) {
			$message = $status === 'queued' ? 'Sync request queued' : 'Sync completed';
			wp_send_json_success( [ 'message' => $message, 'result' => $result ] );
		}

		wp_send_json_error( [ 'message' => 'Sync failed', 'result' => $result ], 400 );
	}

	public function get_sync_progress(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
		}

		$last_sync = get_option( 'ams_last_sync', 'Never' );
		$batcher = new AMS_Batcher();
		$sync_progress = $batcher->get_sync_progress_snapshot();
		$queue_size = $batcher->get_queue_size();
		$active_job = $batcher->get_active_job();
		$failed_count = $batcher->get_failed_queue_count();

		wp_send_json_success( [
			'progress'  => $sync_progress,
			'last_sync' => $last_sync,
			'queue'     => [
				'size'       => $queue_size,
				'active_job' => $active_job,
				'failed_count' => $failed_count,
			],
		] );
	}
}
