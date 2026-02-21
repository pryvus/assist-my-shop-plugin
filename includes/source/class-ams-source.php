<?php

class AMS_Source
{
    use AMS_Logger;

    private array $_source_impls = [];

    public function __construct()
    {
        $this->register_source_impl('post', new AMS_WP_Post_Source());
        $this->register_source_impl('product', new AMS_WC_Product_Source());
        $this->register_source_impl('order', new AMS_WC_Order_Source());
    }

    private function register_source_impl(string $type, AMS_Source_Impl_Interface $impl): void
    {
        $this->_source_impls[$type] = $impl;
    }

    private function get_source_impl(string $type): ?AMS_Source_Impl_Interface
    {
        return $this->_source_impls[$type] ?? null;
    }

    public function get_items(string $type): array
    {
        $source_impl = $this->get_source_impl($type);
        if (! $source_impl) {
            $this->log("Source implementation not found for type: $type");
            return [];
        }
        return $source_impl->get_items();
    }

    public function get_item_by_id(string $type, int $id): array
    {
        $source_impl = $this->get_source_impl($type);
        if (! $source_impl) {
            return [];
        }
        return $source_impl->get_item_by_id($id);
    }

    public function get_items_page(string $type, int $page, int $per_page): array
    {
        $source_impl = $this->get_source_impl($type);
        if (! $source_impl) {
            return [];
        }
        return $source_impl->get_items_page($page, $per_page);
    }
}
