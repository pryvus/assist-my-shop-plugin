<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMS_Sync_Triggers {
	private AMS_Sync_TriggerWP $wp_triggers;
	private AMS_Sync_TriggerWC $wc_triggers;

	public function __construct( AMS_Sync_Orchestrator $orchestrator ) {
		$this->wp_triggers = new AMS_Sync_TriggerWP( $orchestrator );
		$this->wc_triggers = new AMS_Sync_TriggerWC( $orchestrator );
	}

	public function register(): void {
		$this->wp_triggers->register();
		$this->wc_triggers->register();
	}
}
