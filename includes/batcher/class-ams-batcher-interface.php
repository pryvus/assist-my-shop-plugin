<?php

interface AMS_Batcher_Interface {

    public function supports_type( string $type ): bool;

    public function count_items( AMS_Sync_Request $request, string $type ): int;

    public function get_data_chunk( AMS_Sync_Request $request, string $type, int $limit, int $offset ): array;

    public function is_enabled() : bool;
}