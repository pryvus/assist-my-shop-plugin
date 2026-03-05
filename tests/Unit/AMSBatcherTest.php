<?php

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class AMSBatcherTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['ams_test_wp_options'] = [];
		$GLOBALS['ams_test_wp_options']['ams_api_key'] = 'test-api-key';
		$GLOBALS['ams_test_wp_scheduled_events'] = [];
		$GLOBALS['ams_test_wp_filters'] = [];

		AMS_Api_Messenger::$mock_responses = [];
		AMS_Api_Messenger::$requests = [];
		AMS_Batcher_Queue::$queued = [];
		AMS_Batcher_Queue::$failed = [];
		AMS_Batcher_Queue::$requeued = [];

		AMS_Batcher_WC::$enabled = true;
		AMS_Batcher_WC::$supported_types = [ 'product' ];
		AMS_Batcher_WC::$count_by_type = [];
		AMS_Batcher_WC::$chunks = [];

		AMS_Batcher_WP::$enabled = false;
	}

	public function test_do_batch_returns_busy_when_active_job_exists(): void {
		$GLOBALS['ams_test_wp_options']['ams_batcher_active_job'] = [
			'uid' => 'active-uid-1',
		];

		$batcher = new AMS_Batcher();
		$result = $batcher->do_batch();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'busy', $result['status'] );
		$this->assertSame( 'active-uid-1', $result['uid'] );
	}

	public function test_do_batch_returns_idle_when_queue_is_empty(): void {
		$batcher = new AMS_Batcher();
		$result = $batcher->do_batch();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'idle', $result['status'] );
		$this->assertSame( 0, $result['pending_queue_count'] );
	}

	public function test_do_batch_requeues_failed_request_with_backoff(): void {
		AMS_Batcher_Queue::$queued[] = [
			'request_id'   => 'req-1',
			'created_at'   => time(),
			'mode'         => 'full',
			'types'        => [ 'product' ],
			'ids'          => [],
			'reason'       => 'test',
			'batch_size'   => 10,
			'type_index'   => 0,
			'cursor'       => 0,
			'attempts'     => 0,
			'next_run_at'  => 0,
			'type_totals'  => [],
		];

		AMS_Batcher_WC::$count_by_type = [ 'product' => 1 ];
		AMS_Batcher_WC::$chunks = [
			'product:0:10' => [ [ 'id' => 123 ] ],
		];

		AMS_Api_Messenger::$mock_responses = [
			[ 'success' => false, 'error' => 'api fail' ],
		];

		$batcher = new AMS_Batcher();
		$before = time();
		$result = $batcher->do_batch();
		$after = time();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 1, $result['pending_queue_count'] );
		$this->assertCount( 1, AMS_Batcher_Queue::$requeued );
		$this->assertSame( false, AMS_Batcher_Queue::$requeued[0]['front'] );

		$requeued = AMS_Batcher_Queue::$requeued[0]['request'];
		$this->assertSame( 1, (int) $requeued['attempts'] );
		$this->assertGreaterThanOrEqual( $before + 30, (int) $requeued['next_run_at'] );
		$this->assertLessThanOrEqual( $after + 30, (int) $requeued['next_run_at'] );

		$this->assertNotEmpty( $GLOBALS['ams_test_wp_scheduled_events'] );
		$hooks = array_map(
			static fn ( array $event ): string => (string) ( $event['hook'] ?? '' ),
			$GLOBALS['ams_test_wp_scheduled_events']
		);
		$this->assertContains( 'ams_sync_process_queue', $hooks );
	}

	public function test_do_batch_sends_expected_saas_payload_structure(): void {
		AMS_Batcher_Queue::$queued[] = [
			'request_id'   => 'req-structure-1',
			'created_at'   => 1700000000,
			'mode'         => 'full',
			'types'        => [ 'product' ],
			'ids'          => [ 10, 20 ],
			'reason'       => 'manual_sync',
			'batch_size'   => 2,
			'type_index'   => 0,
			'cursor'       => 0,
			'attempts'     => 0,
			'next_run_at'  => 0,
			'type_totals'  => [],
		];

		AMS_Batcher_WC::$count_by_type = [ 'product' => 1 ];
		AMS_Batcher_WC::$chunks = [
			'product:0:2' => [ [ 'id' => 321, 'name' => 'Demo Product' ] ],
		];

		AMS_Api_Messenger::$mock_responses = [
			[ 'success' => true ],
		];

		$batcher = new AMS_Batcher();
		$result = $batcher->do_batch();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'completed', $result['status'] );
		$this->assertCount( 1, AMS_Api_Messenger::$requests );

		$sent = AMS_Api_Messenger::$requests[0];
		$this->assertSame( '/store/sync', $sent['endpoint'] );

		$payload = $sent['data'];
		$this->assertArrayHasKey( 'store_url', $payload );
		$this->assertArrayHasKey( 'api_key', $payload );
		$this->assertArrayHasKey( 'store_info', $payload );
		$this->assertArrayHasKey( 'products', $payload );
		$this->assertArrayNotHasKey( 'posts', $payload );
		$this->assertArrayNotHasKey( 'pages', $payload );

		$this->assertSame( 'https://example.test', $payload['store_url'] );
		$this->assertSame( 'test-api-key', $payload['api_key'] );
		$this->assertIsArray( $payload['store_info'] );
		$this->assertIsArray( $payload['products'] );
		$this->assertCount( 1, $payload['products'] );
		$this->assertSame( 321, $payload['products'][0]['id'] );
	}
}
