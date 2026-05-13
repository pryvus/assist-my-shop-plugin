<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes sync requests to the configured engine.
 *
 * The active engine is read from the `ams_sync_engine` option:
 *   - `action_scheduler` → {@see AMS_Sync_AS} (one AS job per chunk of IDs)
 *   - any other value    → {@see AMS_Batcher} (legacy queue + cron worker)
 */
class AMS_Sync_Orchestrator {
	/**
	 * Legacy batcher engine.
	 *
	 * @var AMS_Batcher
	 */
	private AMS_Batcher $batcher;

	/**
	 * Action Scheduler engine.
	 *
	 * @var AMS_Sync_AS
	 */
	private AMS_Sync_AS $as_engine;

	/**
	 * Constructor.
	 *
	 * @param AMS_Batcher $batcher   Legacy batcher engine.
	 * @param AMS_Sync_AS $as_engine Action Scheduler engine.
	 */
	public function __construct( AMS_Batcher $batcher, AMS_Sync_AS $as_engine ) {
		$this->batcher = $batcher;
		$this->as_engine = $as_engine;
	}

	/**
	 * Dispatch a sync request via the active engine.
	 *
	 * @param AMS_Sync_Request $request Sync request to dispatch.
	 * @return array<string, mixed> Engine-specific dispatch result.
	 */
	public function run( AMS_Sync_Request $request ): array {
		$engine = get_option( 'ams_sync_engine', 'batcher' );

		if ( $engine === 'action_scheduler' ) {
			return $this->as_engine->dispatch( $request );
		}

		$request_id = $this->batcher->enqueue_request( $request );

		return [
			'success'    => true,
			'status'     => 'queued',
			'request_id' => $request_id,
			'message'    => 'Sync request added to queue',
		];
	}
}
