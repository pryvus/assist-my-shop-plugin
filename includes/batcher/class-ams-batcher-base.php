<?php

abstract class AMS_Batcher_Base implements AMS_Batcher_Interface
{

    use Trait_AMS_Logger;
    use Trait_AMS_Sync_Enabled_Types;

    private array $_enabled_types = [];
    private int $_batch_size = 50;
    private int $_cursor = 0;
    private array $_type_scope = [];
    private bool $_is_partial = false;
    protected array $_handled_types = [];

    public function __construct()
    {
        $this->init_enabled_types();
        $this->_enabled_types = array_intersect($this->get_enabled_types(), $this->_handled_types);
    }

    public function is_enabled(): bool
    {
        return ! empty($this->_enabled_types);
    }

    public function supports_type( string $type ): bool
    {
        return in_array( $type, $this->_enabled_types, true ) && in_array( $type, $this->_handled_types, true );
    }

    public function set_batch_context( AMS_Sync_Request $request ): void
    {
        $types = array_values( array_filter( $request->get_types(), 'is_string' ) );
        $reason = $request->get_reason();
        $mode = $request->get_mode();

        $this->_type_scope = $types;
        $this->_is_partial = $mode !== 'full' || in_array( $reason, [ 'delete', 'update', 'create', 'product_update', 'new_order' ], true );
        $this->_cursor = 0;
    }

    public function reset_batch_context(): void
    {
        $this->_cursor = 0;
        $this->_type_scope = [];
        $this->_is_partial = false;
    }

    protected function get_batch_size(): int
    {
        return $this->_batch_size;
    }

    protected function get_cursor(): int
    {
        return $this->_cursor;
    }

    protected function advance_cursor( int $processed ): void
    {
        if ( $processed > 0 ) {
            $this->_cursor += $processed;
        }
    }

    protected function get_type_scope(): array
    {
        return $this->_type_scope;
    }

    protected function is_partial(): bool
    {
        return $this->_is_partial;
    }

    protected function get_ids_for_type( AMS_Sync_Request $request, string $type ): array
    {
        if ( ! $this->is_partial() || ! $this->supports_type( $type ) ) {
            return [];
        }

        if ( ! in_array( $type, $this->get_type_scope(), true ) ) {
            return [];
        }

        $ids = array_map( 'intval', $request->get_ids() );
        return array_values( array_filter( $ids, static fn( int $id ) => $id > 0 ) );
    }
}
