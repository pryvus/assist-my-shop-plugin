<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action Scheduler-driven sync engine.
 *
 * Translates an {@see AMS_Sync_Request} into a set of Action Scheduler jobs,
 * one job per chunk of IDs of a single content type. Each job hydrates its
 * chunk via {@see AMS_Source} and POSTs it to /store/sync via
 * {@see AMS_Api_Messenger}. Progress is stored as a flat `{total, processed}`
 * counter in the `ams_sync_progress` option.
 */
class AMS_Sync_AS {
	use Trait_AMS_Logger;

	/**
	 * Action Scheduler hook the worker fires for each chunk.
	 *
	 * @var string
	 */
	public const HOOK = 'ams_sync_as_chunk';

	/**
	 * Action Scheduler group used to namespace this engine's actions.
	 *
	 * @var string
	 */
	public const GROUP = 'assist-my-shop';

	/**
	 * Maximum number of item IDs carried by a single AS job.
	 *
	 * @var int
	 */
	public const CHUNK_SIZE = 50;

	/**
	 * Option key used to persist sync progress for the admin UI.
	 *
	 * @var string
	 */
	public const PROGRESS_OPTION = 'ams_sync_progress';

	/**
	 * Bind the AS hook to the chunk handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'handle_chunk' ], 10, 2 );
	}

	/**
	 * Translate a sync request into AS jobs and schedule them.
	 *
	 * @param AMS_Sync_Request $request Sync request to dispatch.
	 * @return array<string, mixed> Dispatch result.
	 */
	public function dispatch( AMS_Sync_Request $request ): array {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return [
				'success' => false,
				'status'  => 'failed',
				'message' => 'Action Scheduler is not available',
			];
		}

		$types = $this->resolve_types( $request );
		if ( empty( $types ) ) {
			return [
				'success' => false,
				'status'  => 'idle',
				'message' => 'No content types selected',
			];
		}

		$plan = $this->plan_chunks( $request, $types );
		if ( $plan['total'] === 0 ) {
			return [
				'success' => true,
				'status'  => 'idle',
				'message' => 'Nothing to sync',
			];
		}

		$this->reset_progress( $plan['total'] );

		$now = time();
		$scheduled = 0;
		foreach ( $plan['jobs'] as $job ) {
			$action_id = as_schedule_single_action(
				$now,
				self::HOOK,
				[ $job['type'], $job['ids'] ],
				self::GROUP,
				false
			);
			if ( is_numeric( $action_id ) && (int) $action_id > 0 ) {
				$scheduled++;
			}
		}

		return [
			'success'    => true,
			'status'     => 'queued',
			'request_id' => $request->get_request_id(),
			'message'    => sprintf( 'Scheduled %d AS jobs for %d items', $scheduled, $plan['total'] ),
			'scheduled'  => $scheduled,
			'total'      => $plan['total'],
		];
	}

	/**
	 * Action Scheduler callback: hydrate one chunk and POST it to the SaaS API.
	 *
	 * Throws on API failure so Action Scheduler marks the job failed.
	 *
	 * @param string             $type Content type identifier (e.g. `product`).
	 * @param array<int|string>  $ids  IDs to send for this chunk.
	 * @return void
	 * @throws RuntimeException When the SaaS API rejects the chunk.
	 */
	public function handle_chunk( string $type, array $ids ): void {
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static function ( $id ) {
			return $id > 0;
		} ) );
		if ( empty( $ids ) ) {
			return;
		}
		if ( ! class_exists( 'AMS_Source' ) || ! class_exists( 'AMS_Api_Messenger' ) ) {
			$this->log( 'AMS classes missing in AS chunk handler', [], 'error' );
			return;
		}

		$source = new AMS_Source();
		$items = $source->get_items_by_ids( $type, $ids );

		if ( empty( $items ) ) {
			$this->advance_progress( count( $ids ) );
			return;
		}

		$payload = [
			'store_url'                                => home_url(),
			'store_info'                               => [],
			$this->map_type_to_collection_key( $type ) => $items,
		];

		$response = AMS_Api_Messenger::get()->send_to_saas_api( '/store/sync', $payload );
		$success = isset( $response['success'] ) && $response['success'] === true;

		if ( ! $success ) {
			$error = 'unknown error';
			if ( isset( $response['error'] ) && is_string( $response['error'] ) ) {
				$error = $response['error'];
			} elseif ( isset( $response['message'] ) && is_string( $response['message'] ) ) {
				$error = $response['message'];
			}
			$this->log( 'AS sync chunk failed', [ 'type' => $type, 'count' => count( $items ), 'error' => $error ], 'error' );
			throw new RuntimeException( 'AMS sync chunk failed: ' . $error );
		}

		$this->advance_progress( count( $items ) );
	}

	/**
	 * Resolve the list of content types for a request, falling back to
	 * `ams_post_types` when the request itself carries none.
	 *
	 * @param AMS_Sync_Request $request Sync request.
	 * @return array<int, string> Content type identifiers.
	 */
	private function resolve_types( AMS_Sync_Request $request ): array {
		$types = array_values( array_filter( $request->get_types(), 'is_string' ) );
		if ( empty( $types ) ) {
			$enabled = get_option( 'ams_post_types', [] );
			if ( is_array( $enabled ) ) {
				$types = array_values( array_filter( $enabled, 'is_string' ) );
			}
		}
		return $types;
	}

	/**
	 * Build the job plan for a request.
	 *
	 * @param AMS_Sync_Request    $request Sync request.
	 * @param array<int, string>  $types   Content types to include.
	 * @return array{jobs: array<int, array{type: string, ids: array<int, int>}>, total: int}
	 */
	private function plan_chunks( AMS_Sync_Request $request, array $types ): array {
		$jobs = [];
		$total = 0;

		$partial_reasons = [ 'delete', 'update', 'create', 'product_update', 'new_order' ];
		$is_partial = ! $request->is_full_sync() || in_array( $request->get_reason(), $partial_reasons, true );

		$source_router = new AMS_Source();
		foreach ( $types as $type ) {
			if ( $is_partial ) {
				$ids = array_values( array_filter( array_map( 'intval', $request->get_ids() ), static function ( $id ) {
					return $id > 0;
				} ) );
			} else {
				$ids = $source_router->get_item_ids( $type );
				$ids = array_values( array_filter( array_map( 'intval', $ids ), static function ( $id ) {
					return $id > 0;
				} ) );
			}

			$count = count( $ids );
			$total += $count;

			if ( $count === 0 ) {
				continue;
			}

			$chunks = array_chunk( $ids, self::CHUNK_SIZE );
			foreach ( $chunks as $chunk ) {
				$jobs[] = [ 'type' => $type, 'ids' => $chunk ];
			}
		}

		return [
			'jobs'  => $jobs,
			'total' => $total,
		];
	}

	/**
	 * Initialize the progress option at the start of a dispatch.
	 *
	 * @param int $total Number of items that will be processed.
	 * @return void
	 */
	private function reset_progress( int $total ): void {
		update_option( self::PROGRESS_OPTION, [
			'status'     => 'queued',
			'engine'     => 'action_scheduler',
			'started_at' => time(),
			'total'      => $total,
			'processed'  => 0,
		] );
	}

	/**
	 * Increment the processed counter and mark the run complete when done.
	 *
	 * @param int $delta Items processed in the chunk.
	 * @return void
	 */
	private function advance_progress( int $delta ): void {
		$progress = get_option( self::PROGRESS_OPTION, [] );
		if ( ! is_array( $progress ) || empty( $progress ) ) {
			return;
		}

		$processed = 0;
		if ( isset( $progress['processed'] ) ) {
			$processed = (int) $progress['processed'];
		}
		$processed += max( 0, $delta );

		$total = 0;
		if ( isset( $progress['total'] ) ) {
			$total = (int) $progress['total'];
		}

		$progress['processed'] = $processed;
		$progress['status'] = 'in_progress';

		if ( $total > 0 && $processed >= $total ) {
			$progress['status'] = 'completed';
			$progress['completed_at'] = time();
			update_option( 'ams_last_sync', current_time( 'mysql' ) );
		}

		update_option( self::PROGRESS_OPTION, $progress );
	}

	/**
	 * Map a content type to the SaaS API collection key.
	 *
	 * @param string $type Content type identifier.
	 * @return string Collection key.
	 */
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
}
