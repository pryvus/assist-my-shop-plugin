<?php

class AMS_Batcher {
    use Trait_AMS_Logger;
    
	private const OPTION_ACTIVE_JOB = 'ams_batcher_active_job';
	private const MAX_RETRIES = 3;
	private const CRON_TIME_BUDGET_SECONDS = 25;

	private array $_sources = [];
	private AMS_Api_Messenger $messenger;
	private AMS_Batcher_Queue $queue;

	public function __construct() {
		$this->messenger = AMS_Api_Messenger::get();
		$this->queue = new AMS_Batcher_Queue();
		$this->load_sources();
	}

	private function load_sources(): void {
		$wc_batcher = new AMS_Batcher_WC();
        if ( $wc_batcher->is_enabled() ) {
            $this->_sources[] = $wc_batcher;
        }
        $wp_batcher = new AMS_Batcher_WP();
        if ( $wp_batcher->is_enabled() ) {
            $this->_sources[] = $wp_batcher;
        }
	}

	public function has_active_job(): bool {
		$active_job = get_option( self::OPTION_ACTIVE_JOB, [] );
		return is_array( $active_job ) && ! empty( $active_job['uid'] );
	}

	public function enqueue_request( AMS_Sync_Request $request ): string {
		$request_data = $request->to_array();
		$types = isset( $request_data['types'] ) && is_array( $request_data['types'] )
			? array_values( array_filter( $request_data['types'], 'is_string' ) )
			: [];
		$type_totals = isset( $request_data['type_totals'] ) && is_array( $request_data['type_totals'] )
			? $request_data['type_totals']
			: [];

		$request_data['type_totals'] = $this->resolve_type_totals_for_request( $request_data, $types, $type_totals );

		$request_id = $this->queue->enqueue( $request_data );
		wp_schedule_single_event( time() + 5, 'ams_sync_process_queue' );
		return $request_id;
	}

	public function do_batch(): array {
		if ( $this->has_active_job() ) {
			return [
				'success' => false,
				'status'  => 'busy',
				'uid'     => $this->get_active_uid(),
				'message' => 'Batch job is already running',
			];
		}

		$request = $this->queue->dequeue_ready();
		if ( empty( $request ) ) {
			return [
				'success' => true,
				'status'  => 'idle',
				'message' => 'Queue has no ready requests',
				'pending_queue_count' => $this->get_queue_size(),
			];
		}

		$uid = $this->generate_batch_uid();
		$current_request = $request;
		$this->set_active_job( $uid, $current_request );
		$started_at = microtime( true );
		$did_timeout = false;

		try {
			do {
				$result = $this->process_request_chunk( $uid, $current_request );

				if ( ( $result['status'] ?? '' ) === 'in_progress' && ! empty( $result['request'] ) ) {
					$current_request = $result['request'];
					$this->set_active_job( $uid, $current_request );

					if ( ( microtime( true ) - $started_at ) >= $this->get_cron_time_budget_seconds() ) {
						$did_timeout = true;
						break;
					}
				}
			} while ( ( $result['status'] ?? '' ) === 'in_progress' );
		} finally {
			$this->clear_active_job();
		}

		if ( $did_timeout && ( $result['status'] ?? '' ) === 'in_progress' ) {
            $this->log( 'Batch processing time budget reached, pausing batch job', [ 'uid' => $uid ], 'info' );
			$result['message'] = 'Time budget reached, request re-queued for next cron run';
		}

		if ( ( $result['status'] ?? '' ) === 'in_progress' && ! empty( $result['request'] ) ) {
			$this->queue->requeue( $result['request'], true );
		} elseif ( ( $result['status'] ?? '' ) === 'failed' && ! empty( $result['request'] ) ) {
			$this->handle_failed_request( $result['request'], $result );
		}

		$pending = $this->get_queue_size();
		if ( $pending > 0 ) {
			wp_schedule_single_event( time() + 5, 'ams_sync_process_queue' );
		}

		$result['pending_queue_count'] = $pending;
		return $result;
	}

	private function process_request_chunk( string $uid, array $request ): array {
		$context = $this->build_request_context( $request );

		$completion = $this->build_completed_result_if_done( $uid, $context['request'], 'Request already completed' );
		if ( ! is_null( $completion ) ) {
			return $completion;
		}

		$resolved = $this->resolve_current_type( $uid, $context );
		if ( ! empty( $resolved['result'] ) ) {
			return $resolved['result'];
		}

		$current_type = $resolved['current_type'];
		$source = $resolved['source'];
		$request_object = $context['request_object'];
		$request_state = $resolved['request'];

		$batch_size = (int) $request_state['batch_size'];
		$cursor = (int) $request_state['cursor'];
		$total_for_type = (int) $request_state['type_totals'][ $current_type ];

		$chunk_data = $source->get_data_chunk( $request_object, $current_type, $batch_size, $cursor );
		$processed_count = count( $chunk_data );

		if ( $processed_count > 0 ) {
			$payload = $this->build_chunk_payload(
                $current_type,
                $chunk_data
            );
			$send_result = $this->send_chunk( $payload );
			if ( empty( $send_result['success'] ) ) {
				return $this->build_result( false, 'failed', $uid, $request_state, [
					'message'      => 'Chunk sync request failed',
					'api_response' => $send_result,
				] );
			}
		}

		$request_state = $this->advance_request_state( $request_state, $processed_count, $total_for_type );

		$completion = $this->build_completed_result_if_done( $uid, $request_state );
		if ( ! is_null( $completion ) ) {
			return $completion;
		}

		return $this->build_result( true, 'in_progress', $uid, $request_state );
	}

	private function build_request_context( array $request ): array {
		$request = $this->normalize_runtime_request( $request );

		$request_object = new AMS_Sync_Request(
			mode: $request['mode'],
			types: $request['types'],
			ids: $request['ids'],
			reason: $request['reason'],
			request_id: $request['request_id'],
			created_at: $request['created_at']
		);

		return [
			'request'        => $request,
			'request_object' => $request_object,
		];
	}

	private function resolve_current_type( string $uid, array $context ): array {
		$request_state = $context['request'];
		$request_object = $context['request_object'];

		$types = $request_state['types'];
		$type_index = (int) $request_state['type_index'];
		$current_type = $types[ $type_index ];

		$source = $this->find_source_for_type( $current_type );
		if ( ! $source ) {
			$request_state['type_index'] = $type_index + 1;
			$request_state['cursor'] = 0;

			return [
				'result' => $this->build_result( true, 'in_progress', $uid, $request_state, [
					'message' => 'No source for type, moving to next type',
				] ),
			];
		}

		$totals = is_array( $request_state['type_totals'] ) ? $request_state['type_totals'] : [];
		if ( ! isset( $totals[ $current_type ] ) ) {
			$totals[ $current_type ] = $source->count_items( $request_object, $current_type );
			$request_state['type_totals'] = $totals;
		}

		return [
			'current_type' => $current_type,
			'source'       => $source,
			'request'      => $request_state,
			'result'       => null,
		];
	}

	private function build_chunk_payload(
		string $current_type,
		array $chunk_data
	): array {
		$collections = $this->build_sync_collections( $current_type, $chunk_data );

		return array_merge( [
			'store_url' => home_url(),
			'store_info' => [],
		], $collections );
	}

	private function build_sync_collections( string $current_type, array $chunk_data ): array {
		$collections = [];
		$collection_key = $this->map_type_to_collection_key( $current_type );
		if ( count( $chunk_data ) > 0 ) {
			$collections[ $collection_key ] = $chunk_data;
		}

		return $collections;
	}

	private function map_type_to_collection_key( string $type ): string {
		if ( $type === 'product' ) {
			return 'products';
		}

		if ( $type === 'post' ) {
			return 'posts';
		}

		if ( $type === 'page' ) {
			return 'pages';
		}

		return $type;
	}

	private function send_chunk( array $payload ): array {
		$response = $this->messenger->send_to_saas_api( '/store/sync', $payload );
		return is_array( $response ) ? $response : [];
	}

	private function advance_request_state( array $request_state, int $processed_count, int $total_for_type ): array {
		$cursor = (int) $request_state['cursor'];
		$type_index = (int) $request_state['type_index'];

		$request_state['cursor'] = $cursor + $processed_count;
		$finished_type = $processed_count === 0 || $request_state['cursor'] >= $total_for_type;

		if ( $finished_type ) {
			$request_state['type_index'] = $type_index + 1;
			$request_state['cursor'] = 0;
		}

		return $request_state;
	}

	private function build_completed_result_if_done( string $uid, array $request_state, string $message = '' ): ?array {
		$types = $request_state['types'] ?? [];
		$type_index = (int) ( $request_state['type_index'] ?? 0 );
		if ( $type_index < count( $types ) ) {
			return null;
		}

		update_option( 'ams_last_sync', current_time( 'mysql' ) );
		$extra = [];
		if ( $message !== '' ) {
			$extra['message'] = $message;
		}

		return $this->build_result( true, 'completed', $uid, $request_state, $extra );
	}

	private function build_result( bool $success, string $status, string $uid, array $request_state, array $extra = [] ): array {
		return array_merge( [
			'success' => $success,
			'status'  => $status,
			'uid'     => $uid,
			'request' => $request_state,
		], $extra );
	}

	private function handle_failed_request( array $request, array $result ): void {
		$attempts = (int) ( $request['attempts'] ?? 0 ) + 1;
		$request['attempts'] = $attempts;

		if ( $attempts < self::MAX_RETRIES ) {
			$backoff_seconds = 30 * ( 2 ** ( $attempts - 1 ) );
			$request['next_run_at'] = time() + $backoff_seconds;
			$this->queue->requeue( $request, false );
			return;
		}

		$this->queue->mark_failed( $request, $result );
	}

	private function find_source_for_type( string $type ): ?AMS_Batcher_Interface {
		foreach ( $this->_sources as $source ) {
			if ( $source->supports_type( $type ) ) {
				return $source;
			}
		}

		return null;
	}

	private function normalize_runtime_request( array $request ): array {
		$types = isset( $request['types'] ) && is_array( $request['types'] )
			? array_values( array_filter( $request['types'], 'is_string' ) )
			: [];

		if ( empty( $types ) ) {
			$types = get_option( 'ams_post_types', [] );
			$types = is_array( $types ) ? array_values( array_filter( $types, 'is_string' ) ) : [];
		}

		return [
			'request_id'  => (string) ( $request['request_id'] ?? $this->generate_batch_uid() ),
			'created_at'  => (int) ( $request['created_at'] ?? time() ),
			'mode'        => (string) ( $request['mode'] ?? 'full' ),
			'types'       => $types,
			'ids'         => isset( $request['ids'] ) && is_array( $request['ids'] ) ? array_values( array_map( 'intval', $request['ids'] ) ) : [],
			'reason'      => (string) ( $request['reason'] ?? '' ),
			'batch_size'  => max( 1, (int) ( $request['batch_size'] ?? 50 ) ),
			'type_index'  => max( 0, (int) ( $request['type_index'] ?? 0 ) ),
			'cursor'      => max( 0, (int) ( $request['cursor'] ?? 0 ) ),
			'attempts'    => max( 0, (int) ( $request['attempts'] ?? 0 ) ),
			'next_run_at' => (int) ( $request['next_run_at'] ?? 0 ),
			'type_totals' => isset( $request['type_totals'] ) && is_array( $request['type_totals'] ) ? $request['type_totals'] : [],
		];
	}

	private function get_active_uid(): string {
		$active_job = get_option( self::OPTION_ACTIVE_JOB, [] );
		if ( ! is_array( $active_job ) ) {
			return '';
		}

		return (string) ( $active_job['uid'] ?? '' );
	}

	private function set_active_job( string $uid, array $request ): void {
		update_option( self::OPTION_ACTIVE_JOB, [
			'uid'        => $uid,
			'started_at' => time(),
			'request'    => $request,
		] );
	}

	private function clear_active_job(): void {
		delete_option( self::OPTION_ACTIVE_JOB );
	}

	public function get_queue_size(): int {
		return $this->queue->size();
	}

	public function get_queue_items(): array {
		$queue_items = $this->queue->all();
		return is_array( $queue_items ) ? $queue_items : [];
	}

	public function get_active_job(): array {
		$active_job = get_option( self::OPTION_ACTIVE_JOB, [] );
		return is_array( $active_job ) ? $active_job : [];
	}

	public function get_failed_queue_count(): int {
		return $this->queue->failed_count();
	}

	public function get_sync_progress_snapshot(): ?array {
		$active_job = $this->get_active_job();
		if ( is_array( $active_job ) && ! empty( $active_job['request'] ) && is_array( $active_job['request'] ) ) {
			return $this->build_progress_from_request( $active_job['request'], true );
		}

		$queue = $this->queue->all();
		if ( ! empty( $queue ) && is_array( $queue[0] ?? null ) ) {
			return $this->build_progress_from_request( $queue[0], false );
		}

		$legacy_progress = get_option( 'ams_sync_progress', null );
		return is_array( $legacy_progress ) ? $legacy_progress : null;
	}

	private function build_progress_from_request( array $request, bool $is_active ): array {
		$types = isset( $request['types'] ) && is_array( $request['types'] )
			? array_values( array_filter( $request['types'], 'is_string' ) )
			: [];

		$type_index = max( 0, (int) ( $request['type_index'] ?? 0 ) );
		$cursor = max( 0, (int) ( $request['cursor'] ?? 0 ) );
		$type_totals = isset( $request['type_totals'] ) && is_array( $request['type_totals'] )
			? $request['type_totals']
			: [];
		$type_totals = $this->resolve_type_totals_for_request( $request, $types, $type_totals );

		$overall_total = 0;
		$overall_processed = 0;

		foreach ( $types as $index => $type ) {
			$total = max( 0, (int) ( $type_totals[ $type ] ?? 0 ) );
			$overall_total += $total;

			if ( $index < $type_index ) {
				$overall_processed += $total;
			} elseif ( $index === $type_index ) {
				$overall_processed += min( $cursor, $total );
			}
		}

		$current_post_type = '';
		$current_total = 0;
		$current_processed = 0;

		if ( $type_index < count( $types ) ) {
			$current_post_type = (string) $types[ $type_index ];
			$current_total = max( 0, (int) ( $type_totals[ $current_post_type ] ?? 0 ) );
			$current_processed = min( $cursor, $current_total );
		}

		return [
			'overall_total'      => $overall_total,
			'overall_processed'  => $overall_processed,
			'current_post_type'  => $current_post_type,
			'current_total'      => $current_total,
			'current_processed'  => $current_processed,
			'status'             => $is_active ? 'in_progress' : 'queued',
		];
	}

	private function resolve_type_totals_for_request( array $request, array $types, array $type_totals ): array {
		if ( empty( $types ) ) {
			return $type_totals;
		}

		$request_object = new AMS_Sync_Request(
			mode: (string) ( $request['mode'] ?? 'full' ),
			types: $types,
			ids: isset( $request['ids'] ) && is_array( $request['ids'] ) ? array_values( array_map( 'intval', $request['ids'] ) ) : [],
			reason: (string) ( $request['reason'] ?? '' ),
			request_id: (string) ( $request['request_id'] ?? $this->generate_batch_uid() ),
			created_at: (int) ( $request['created_at'] ?? time() )
		);

		foreach ( $types as $type ) {
			if ( isset( $type_totals[ $type ] ) ) {
				continue;
			}

			$source = $this->find_source_for_type( $type );
			$type_totals[ $type ] = $source ? max( 0, (int) $source->count_items( $request_object, $type ) ) : 0;
		}

		return $type_totals;
	}

	private function get_cron_time_budget_seconds(): int {
		$default = self::CRON_TIME_BUDGET_SECONDS;
		$value = apply_filters( 'ams_sync_cron_time_budget_seconds', $default );
		return max( 5, (int) $value );
	}

	private function generate_batch_uid(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'ams_batch_', true );
	}
}
