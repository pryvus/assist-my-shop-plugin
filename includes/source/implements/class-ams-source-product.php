<?php

class AMS_Source_Product implements AMS_Source_Interface
{
    /**
     * Get total count of items in the source
     * @return int
     */
    public function get_items_count(): int
    {
        $products = wc_get_products([
            'limit' => -1,
            'return' => 'ids',
        ]);
        $product_ids = $this->prepare_ids($products);
        return count($product_ids);
    }

    public function get_item_ids(): array
    {
        $products = wc_get_products([
            'limit' => -1,
            'return' => 'ids',
        ]);

        return array_values(array_map('intval', $this->prepare_ids($products)));
    }

    /**
     * Get all items from the source
     * @return array
     */
    public function get_items(): array
    {
        $products = wc_get_products([
            'limit' => -1,
            'return' => 'ids',
        ]);
        return $this->get_formatted_products($products);
    }

    /**
     * Get single item by ID
     * @param int $id
     * @return array|null
     */
    public function get_item_by_id(int $id): array | null
    {
        $post = get_post($id);
        if (! $post) {
            return null;
        }

        return [
            'id'                => $id,
            'name'              => $post->post_title,
            'description'       => $post->post_content,
            'short_description' => $post->post_excerpt,
            'price'             => $this->get_product_price($id, 'price'),
            'regular_price'     => $this->get_product_price($id, 'regular_price'),
            'sale_price'        => $this->get_product_price($id, 'sale_price'),
            'stock_quantity'    => $this->get_stock_quantity($id),
            'stock_status'      => get_post_meta($id, '_stock_status', true),
            'sku'               => get_post_meta($id, '_sku', true),
            'url'               => get_permalink($id),
            'categories'        => wp_get_post_terms($id, 'product_cat', ['fields' => 'names']),
            'tags'              => wp_get_post_terms($id, 'product_tag', ['fields' => 'names']),
            'image_url'         => $this->get_product_image_url($id),
            'attributes'        => $this->get_product_attributes($id),
            'type'              => 'product',
        ];
    }

    public function get_items_by_ids(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($id) => $id > 0));
        if (empty($ids)) {
            return [];
        }

        $products = wc_get_products([
            'include' => $ids,
            'limit'   => count($ids),
            'return'  => 'ids',
        ]);

        return $this->get_formatted_products((array) $products);
    }

    /**
     * Get product price by meta key
     * @param int $id
     * @param string $type (price, regular_price, sale_price)
     * @return string|false
     */
    private function get_product_price(int $id, string $type): string | false
    {
        $meta_key_map = [
            'price'         => '_price',
            'regular_price' => '_regular_price',
            'sale_price'    => '_sale_price',
        ];
        $key = $meta_key_map[$type] ?? null;
        return $key ? get_post_meta($id, $key, true) : false;
    }

    /**
     * Get stock quantity
     * @param int $id
     * @return int|null
     */
    private function get_stock_quantity(int $id): int | null
    {
        $stock = get_post_meta($id, '_stock', true);
        return is_numeric($stock) ? intval($stock) : null;
    }

    /**
     * Get product thumbnail URL
     * @param int $id
     * @return string
     */
    private function get_product_image_url(int $id): string
    {
        $thumb_id = get_post_thumbnail_id($id);
        return $thumb_id ? wp_get_attachment_url($thumb_id) : '';
    }

    /**
     * Extract product attributes
     * @param int $id
     * @return array
     */
    private function get_product_attributes(int $id): array
    {
        if (! function_exists('wc_get_product')) {
            return [];
        }

        $product = wc_get_product($id);
        if (! $product || ! method_exists($product, 'get_attributes')) {
            return [];
        }

        $attributes = [];
        $prod_attrs = $product->get_attributes();

        foreach ($prod_attrs as $attr) {
            if (! is_object($attr) || ! method_exists($attr, 'get_name')) {
                continue;
            }

            $attr_data = $this->parse_product_attribute($attr);
            if ($attr_data) {
                $attributes[] = $attr_data;
            }
        }

        return $attributes;
    }

    /**
     * Parse single product attribute
     * @param object $attr WC_Product_Attribute object
     * @return array|null
     */
    private function parse_product_attribute(object $attr): array | null
    {
        if (! method_exists($attr, 'get_name')) {
            return null;
        }

        $attr_name = $attr->get_name();
        $is_tax = method_exists($attr, 'is_taxonomy') && $attr->is_taxonomy();
        $options = method_exists($attr, 'get_options') ? $attr->get_options() : [];

        $values = $is_tax ? $this->extract_taxonomy_values($options) : $options;
        $label = $this->get_attribute_label($attr_name, $is_tax);

        return [
            'name'    => $label,
            'slug'    => $attr_name,
            'options' => array_values($values),
        ];
    }

    /**
     * Extract values from taxonomy attribute options
     * @param array $options
     * @return array
     */
    private function extract_taxonomy_values(array $options): array
    {
        $values = [];
        foreach ($options as $opt) {
            $term = get_term($opt);
            $values[] = ($term && ! is_wp_error($term)) ? $term->name : $opt;
        }
        return $values;
    }

    /**
     * Get attribute label
     * @param string $attr_name
     * @param bool $is_taxonomy
     * @return string
     */
    private function get_attribute_label(string $attr_name, bool $is_taxonomy): string
    {
        if ($is_taxonomy && function_exists('wc_attribute_label')) {
            return wc_attribute_label($attr_name);
        }
        return $attr_name;
    }

    /**
     * Get items for a specific page
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public function get_items_page(int $page, int $per_page): array
    {
        $offset = ($page - 1) * $per_page;
        $products = wc_get_products([
            'limit' => $per_page,
            'offset' => $offset,
            'return' => 'ids',
        ]);
        return $this->get_formatted_products($products);
    }

    /**
     * Format products into standardized array
     * @param array $product_ids
     * @return array
     */
    private function get_formatted_products(array $product_ids): array
    {
        $product_ids = $this->prepare_ids($product_ids);
        $result = [];
        foreach ($product_ids as $pid) {
            $data = $this->get_item_by_id($pid);
            if ($data) {
                $result[] = $data;
            }
        }
        return $result;
    }

    /**
     * Prepare product IDs from wc_get_products result
     * @param array $maybe_ids
     * @return array
     */
    private function prepare_ids(array $maybe_ids): array
    {
        $product_ids = [];

        // If wc_get_products returned objects, convert to IDs
        if (is_array($maybe_ids) && isset($maybe_ids[0]) && is_object($maybe_ids[0])) {
            $ids = [];
            foreach ($maybe_ids as $p) {
                if (is_object($p) && method_exists($p, 'get_id')) {
                    $ids[] = $p->get_id();
                }
            }
            $maybe_ids = $ids;
        } else {
            // assume it's array of IDs
            $maybe_ids = (array) $maybe_ids;
        }

        // Expand variable products into their variation IDs so each variation
        // is counted as a separate item (matches variation matrix)
        foreach ($maybe_ids as $maybe_id) {
            $pid = (int) $maybe_id;
            if ($pid <= 0) {
                continue;
            }
            $product = function_exists('wc_get_product') ? wc_get_product($pid) : null;
            if ($product && method_exists($product, 'is_type') && $product->is_type('variable')) {
                $children = $product->get_children();
                if (!empty($children)) {
                    foreach ($children as $child_id) {
                        $product_ids[] = (int) $child_id;
                    }
                    continue;
                }
            }
            $product_ids[] = $pid;
        }

        return array_values(array_unique($product_ids));
    }
}
