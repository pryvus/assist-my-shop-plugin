<?php

interface AMS_Source_Impl_Interface {
    public function get_items_count(): int;
    public function get_items(): array;
    public function get_item_by_id( int $id ): array | null;
    public function get_items_page( int $page, int $per_page ): array;
}