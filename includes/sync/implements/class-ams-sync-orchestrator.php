<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMS_Sync_Orchestrator {
	private AMS_Batcher $batcher;

	public function __construct( AMS_Batcher $batcher ) {
		$this->batcher = $batcher;
	}

	public function run( AMS_Sync_Request $request ): array {
		$request_id = $this->batcher->enqueue_request( $request );

		return [
			'success'    => true,
			'status'     => 'queued',
			'request_id' => $request_id,
			'message'    => 'Sync request added to queue',
		];
	}
}
