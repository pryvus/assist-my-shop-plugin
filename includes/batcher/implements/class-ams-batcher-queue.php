<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMS_Batcher_Queue {
	private const OPTION_QUEUE = 'ams_batcher_queue';
	private const OPTION_FAILED_QUEUE = 'ams_batcher_failed_queue';

	public function enqueue( array $request ): string {
		$normalized = $this->normalize_request( $request );
		$queue = $this->all();
		$queue[] = $normalized;
		update_option( self::OPTION_QUEUE, $queue );

		return (string) $normalized['request_id'];
	}

	public function dequeue_ready(): ?array {
		$queue = $this->all();
		if ( empty( $queue ) ) {
			return null;
		}

		$now = time();
		$ready_index = null;
		foreach ( $queue as $index => $request ) {
			$next_run_at = (int) ( $request['next_run_at'] ?? 0 );
			if ( $next_run_at <= $now ) {
				$ready_index = $index;
				break;
			}
		}

		if ( $ready_index === null ) {
			return null;
		}

		$request = $queue[ $ready_index ];
		unset( $queue[ $ready_index ] );
		$queue = array_values( $queue );
		update_option( self::OPTION_QUEUE, $queue );

		return is_array( $request ) ? $this->normalize_request( $request ) : null;
	}

	public function requeue( array $request, bool $front = true ): void {
		$normalized = $this->normalize_request( $request );
		$queue = $this->all();
		if ( $front ) {
			array_unshift( $queue, $normalized );
		} else {
			$queue[] = $normalized;
		}

		update_option( self::OPTION_QUEUE, $queue );
	}

	public function mark_failed( array $request, array $result = [] ): void {
		$failed = get_option( self::OPTION_FAILED_QUEUE, [] );
		if ( ! is_array( $failed ) ) {
			$failed = [];
		}

		$failed[] = [
			'request'   => $this->normalize_request( $request ),
			'result'    => $result,
			'failed_at' => time(),
		];

		update_option( self::OPTION_FAILED_QUEUE, $failed );
	}

	public function size(): int {
		return count( $this->all() );
	}

	public function all(): array {
		$queue = get_option( self::OPTION_QUEUE, [] );
		return is_array( $queue ) ? $queue : [];
	}

	public function failed_count(): int {
		$failed = get_option( self::OPTION_FAILED_QUEUE, [] );
		return is_array( $failed ) ? count( $failed ) : 0;
	}

	private function normalize_request( array $request ): array {
		$mode = isset( $request['mode'] ) && is_string( $request['mode'] ) ? $request['mode'] : 'full';
		$types = isset( $request['types'] ) && is_array( $request['types'] ) ? array_values( array_filter( $request['types'], 'is_string' ) ) : [];
		if ( empty( $types ) ) {
			$types = get_option( 'ams_post_types', [] );
			$types = is_array( $types ) ? array_values( array_filter( $types, 'is_string' ) ) : [];
		}

		$ids = isset( $request['ids'] ) && is_array( $request['ids'] ) ? array_values( array_map( 'intval', $request['ids'] ) ) : [];
		$request_id = isset( $request['request_id'] ) && is_string( $request['request_id'] ) && $request['request_id'] !== ''
			? $request['request_id']
			: $this->generate_request_id();

		$batch_size = isset( $request['batch_size'] ) ? max( 1, (int) $request['batch_size'] ) : 50;

		return [
			'request_id'   => $request_id,
			'created_at'   => isset( $request['created_at'] ) ? (int) $request['created_at'] : time(),
			'mode'         => $mode,
			'types'        => $types,
			'ids'          => $ids,
			'reason'       => isset( $request['reason'] ) && is_string( $request['reason'] ) ? $request['reason'] : '',
			'batch_size'   => $batch_size,
			'type_index'   => isset( $request['type_index'] ) ? (int) $request['type_index'] : 0,
			'cursor'       => isset( $request['cursor'] ) ? (int) $request['cursor'] : 0,
			'attempts'     => isset( $request['attempts'] ) ? (int) $request['attempts'] : 0,
			'next_run_at'  => isset( $request['next_run_at'] ) ? (int) $request['next_run_at'] : 0,
			'type_totals'  => isset( $request['type_totals'] ) && is_array( $request['type_totals'] ) ? $request['type_totals'] : [],
		];
	}

	private function generate_request_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'ams_sync_', true );
	}
}
