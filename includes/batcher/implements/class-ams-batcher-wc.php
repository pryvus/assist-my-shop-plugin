<?php

class AMS_Batcher_WC extends AMS_Batcher_Base implements AMS_Batcher_Interface {

    protected array $_handled_types = [ 'product', 'order' ];
    private AMS_Source $source;

    public function __construct() {
        parent::__construct();
        $this->source = new AMS_Source();
    }

    public function count_items( AMS_Sync_Request $request, string $type ): int {
        $this->set_batch_context( $request );

        if ( ! $this->supports_type( $type ) ) {
            return 0;
        }

        if ( $this->is_partial() ) {
            return count( $this->get_ids_for_type( $request, $type ) );
        }

        return $this->source->get_items_count( $type );
    }

    public function get_data_chunk( AMS_Sync_Request $request, string $type, int $limit, int $offset ): array {
        $this->set_batch_context( $request );

        if ( ! $this->supports_type( $type ) ) {
            return [];
        }

        if ( $this->is_partial() ) {
            $ids = $this->get_ids_for_type( $request, $type );
            $chunk_ids = array_slice( array_values( array_map( 'intval', $ids ) ), $offset, $limit );
            return $this->source->get_items_by_ids( $type, $chunk_ids );
        }

        $ids = $this->source->get_item_ids( $type );
        $chunk_ids = array_slice( array_values( array_map( 'intval', $ids ) ), $offset, $limit );
        return $this->source->get_items_by_ids( $type, $chunk_ids );
    }
}