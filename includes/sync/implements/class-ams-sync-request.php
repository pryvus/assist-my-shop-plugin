<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMS_Sync_Request {
	private string $request_id;
	private int $created_at;
	private string $mode;
	private array $types;
	private array $ids;
	private string $reason;

	public function __construct( string $mode = 'full', array $types = [], array $ids = [], string $reason = '', string $request_id = '', int $created_at = 0 ) {
		$this->request_id = $request_id !== '' ? $request_id : $this->generate_request_id();
		$this->created_at = $created_at > 0 ? $created_at : time();
		$this->mode = $mode;
		$this->types = $types;
		$this->ids = $ids;
		$this->reason = $reason;
	}

	public function get_request_id(): string {
		return $this->request_id;
	}

	public function get_created_at(): int {
		return $this->created_at;
	}

	public function get_mode(): string {
		return $this->mode;
	}

	public function get_types(): array {
		return $this->types;
	}

	public function get_ids(): array {
		return $this->ids;
	}

	public function get_reason(): string {
		return $this->reason;
	}

	public function is_full_sync(): bool {
		return $this->mode === 'full';
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

	public static function from_array( array $data ): self {
		return new self(
			$data['mode'] ?? 'full',
			$data['types'] ?? [],
			$data['ids'] ?? [],
			$data['reason'] ?? '',
			$data['request_id'] ?? '',
			$data['created_at'] ?? 0
		);
	}

	private function generate_request_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'ams_sync_', true );
	}
}
