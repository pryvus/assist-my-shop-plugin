<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Trait_AMS_Sync_Enabled_Types {
	protected array $enabled_types = [];

	protected function init_enabled_types(): void {
		$types = get_option( 'ams_post_types', [] );
		$this->enabled_types = is_array( $types ) ? $types : [];
	}

	protected function is_type_enabled( string $type ): bool {
		return in_array( $type, $this->enabled_types, true );
	}

	protected function get_enabled_types(): array {
		return $this->enabled_types;
	}
}