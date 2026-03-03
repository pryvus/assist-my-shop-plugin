<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$GLOBALS['ams_test_wp_options'] = [];
$GLOBALS['ams_test_wp_scheduled_events'] = [];
$GLOBALS['ams_test_wp_filters'] = [];

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['ams_test_wp_options'] )
			? $GLOBALS['ams_test_wp_options'][ $name ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value ): bool {
		$GLOBALS['ams_test_wp_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['ams_test_wp_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ): bool {
		$GLOBALS['ams_test_wp_scheduled_events'][] = [
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
		];
		return true;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url(): string {
		return 'https://example.test';
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		if ( $type === 'mysql' ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		return (string) time();
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value ) {
		if ( ! isset( $GLOBALS['ams_test_wp_filters'][ $tag ] ) ) {
			return $value;
		}

		$callback = $GLOBALS['ams_test_wp_filters'][ $tag ];
		return $callback( $value );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return uniqid( 'test_uuid_', true );
	}
}

if ( ! interface_exists( 'AMS_Batcher_Interface' ) ) {
	interface AMS_Batcher_Interface {
		public function supports_type( string $type ): bool;
		public function count_items( AMS_Sync_Request $request, string $type ): int;
		public function get_data_chunk( AMS_Sync_Request $request, string $type, int $limit, int $offset ): array;
		public function is_enabled(): bool;
	}
}

if ( ! class_exists( 'AMS_Sync_Request' ) ) {
	class AMS_Sync_Request {
		private string $request_id;
		private int $created_at;
		private string $mode;
		private array $types;
		private array $ids;
		private string $reason;

		public function __construct( string $mode = 'full', array $types = [], array $ids = [], string $reason = '', string $request_id = '', int $created_at = 0 ) {
			$this->request_id = $request_id !== '' ? $request_id : uniqid( 'req_', true );
			$this->created_at = $created_at > 0 ? $created_at : time();
			$this->mode = $mode;
			$this->types = $types;
			$this->ids = $ids;
			$this->reason = $reason;
		}

		public function get_mode(): string {
			return $this->mode;
		}

		public function get_reason(): string {
			return $this->reason;
		}

		public function to_array(): array {
			return [
				'request_id' => $this->request_id,
				'created_at' => $this->created_at,
				'mode'       => $this->mode,
				'types'      => $this->types,
				'ids'        => $this->ids,
				'reason'     => $this->reason,
			];
		}
	}
}

if ( ! class_exists( 'AMS_Api_Messenger' ) ) {
	class AMS_Api_Messenger {
		public static array $mock_responses = [];
		public static array $requests = [];
		private static ?AMS_Api_Messenger $instance = null;

		public static function get(): AMS_Api_Messenger {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function send_to_saas_api( string $endpoint, array $data ): array {
			if ( ! empty( $data ) ) {
				$data['api_key'] = (string) get_option( 'ams_api_key', '' );
			}

			self::$requests[] = [
				'endpoint' => $endpoint,
				'data'     => $data,
			];

			if ( empty( self::$mock_responses ) ) {
				return [ 'success' => true ];
			}
			$response = array_shift( self::$mock_responses );
			return is_array( $response ) ? $response : [];
		}
	}
}

if ( ! class_exists( 'AMS_Batcher_Queue' ) ) {
	class AMS_Batcher_Queue {
		public static array $queued = [];
		public static array $failed = [];
		public static array $requeued = [];

		public function enqueue( array $request ): string {
			self::$queued[] = $request;
			return (string) ( $request['request_id'] ?? uniqid( 'req_', true ) );
		}

		public function dequeue_ready(): ?array {
			if ( empty( self::$queued ) ) {
				return null;
			}
			$request = array_shift( self::$queued );
			return is_array( $request ) ? $request : null;
		}

		public function requeue( array $request, bool $front = true ): void {
			self::$requeued[] = [
				'request' => $request,
				'front'   => $front,
			];

			if ( $front ) {
				array_unshift( self::$queued, $request );
				return;
			}

			self::$queued[] = $request;
		}

		public function mark_failed( array $request, array $result = [] ): void {
			self::$failed[] = [
				'request' => $request,
				'result'  => $result,
			];
		}

		public function size(): int {
			return count( self::$queued );
		}

		public function failed_count(): int {
			return count( self::$failed );
		}
	}
}

if ( ! class_exists( 'AMS_Batcher_WC' ) ) {
	class AMS_Batcher_WC implements AMS_Batcher_Interface {
		public static bool $enabled = true;
		public static array $supported_types = [ 'product' ];
		public static array $count_by_type = [];
		public static array $chunks = [];

		public function supports_type( string $type ): bool {
			return in_array( $type, self::$supported_types, true );
		}

		public function count_items( AMS_Sync_Request $request, string $type ): int {
			if ( isset( self::$count_by_type[ $type ] ) ) {
				return (int) self::$count_by_type[ $type ];
			}
			return 0;
		}

		public function get_data_chunk( AMS_Sync_Request $request, string $type, int $limit, int $offset ): array {
			$key = $type . ':' . $offset . ':' . $limit;
			if ( isset( self::$chunks[ $key ] ) && is_array( self::$chunks[ $key ] ) ) {
				return self::$chunks[ $key ];
			}
			return [];
		}

		public function is_enabled(): bool {
			return self::$enabled;
		}
	}
}

if ( ! class_exists( 'AMS_Batcher_WP' ) ) {
	class AMS_Batcher_WP extends AMS_Batcher_WC {
		public static bool $enabled = false;
	}
}

require_once __DIR__ . '/../includes/batcher/class-ams-batcher.php';
