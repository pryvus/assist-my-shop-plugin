<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AMS_Sync_TriggerWP {
	use Trait_AMS_Sync_Enabled_Types;

	private AMS_Sync_Orchestrator $orchestrator;

	public function __construct( AMS_Sync_Orchestrator $orchestrator ) {
		$this->orchestrator = $orchestrator;
		$this->init_enabled_types();
	}

	public function register(): void {
		if ( ! $this->is_type_enabled( 'post' ) ) {
			return;
		}

		add_action( 'save_post', [ $this, 'on_post_change' ], 10, 3 );
		add_action( 'delete_post', [ $this, 'on_post_delete' ] );
		add_action( 'wp_trash_post', [ $this, 'on_post_delete' ] );
	}

	public function on_post_change( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( $post->post_status !== 'publish' ) {
			return;
		}

		if ( $post->post_type !== 'post' || ! $this->is_type_enabled( 'post' ) ) {
			return;
		}

		$request = new AMS_Sync_Request( 'delta', [ $post->post_type ], [ $post_id ], $update ? 'update' : 'create' );
		$this->orchestrator->run( $request );
	}

	public function on_post_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		if ( $post->post_type !== 'post' || ! $this->is_type_enabled( 'post' ) ) {
			return;
		}

		$request = new AMS_Sync_Request( 'delta', [ $post->post_type ], [ $post_id ], 'delete' );
		$this->orchestrator->run( $request );
	}

}