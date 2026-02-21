<?php

/**
 * WooCommerce Order Data Source
 * 
 * Provides order data with privacy regulation compliance.
 * Uses Trait_AMS_Data_Privacy for handling:
 * - GDPR (EU/EEA)
 * - CCPA/CPRA (California)
 * - LGPD (Brazil)
 * - PIPEDA (Canada)
 */
class AMS_WC_Order_Source implements AMS_Source_Impl_Interface
{
    use Trait_AMS_Data_Privacy;
    /**
     * Get total count of orders
     * @return int
     */
    public function get_items_count(): int
    {
        $orders = wc_get_orders([
            'limit' => -1,
            'return' => 'ids',
        ]);
        return is_array($orders) ? count($orders) : 0;
    }

    /**
     * Get all orders
     * @return array
     */
    public function get_items(): array
    {
        $orders = wc_get_orders([
            'limit' => -1,
            'return' => 'ids',
        ]);
        return $this->get_formatted_orders($orders);
    }

    /**
     * Get single order by ID
     * @param int $id
     * @return array|null
     */
    public function get_item_by_id(int $id): array | null
    {
        if (!function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($id);
        if (!$order) {
            return null;
        }

        $order_data = [
            'id'              => $order->get_id(),
            'status'          => $order->get_status(),
            'title'           => $this->get_order_title($order),
            'date_created'    => $this->format_order_date($order->get_date_created()),
            'total'           => $order->get_total(),
            'currency'        => $order->get_currency(),
            'payment_method'  => $order->get_payment_method_title(),
            'items_count'     => count($order->get_items()),
            'items'           => $this->get_order_items($order),
        ];

        // Add privacy compliance info
        $privacy_info = $this->get_privacy_info($order);
        $order_data['privacy'] = $privacy_info;

        // Add personal data if allowed
        $personal_data = $this->get_allowed_personal_data($order);
        if (!empty($personal_data)) {
            $order_data = array_merge($order_data, $personal_data);
        }

        return $order_data;
    }

    /**
     * Get orders for a specific page
     * @param int $page
     * @param int $per_page
     * @return array
     */
    public function get_items_page(int $page, int $per_page): array
    {
        $offset = ($page - 1) * $per_page;
        $orders = wc_get_orders([
            'limit'  => $per_page,
            'offset' => $offset,
            'return' => 'ids',
        ]);
        return $this->get_formatted_orders($orders);
    }

    /**
     * Format orders into standardized array
     * @param array $order_ids
     * @return array
     */
    private function get_formatted_orders(array $order_ids): array
    {
        $result = [];
        foreach ($order_ids as $order_id) {
            $data = $this->get_item_by_id($order_id);
            if ($data) {
                $result[] = $data;
            }
        }
        return $result;
    }

    /**
     * Get order title (Order #ID)
     * @param WC_Order $order
     * @return string
     */
    private function get_order_title($order): string
    {
        return sprintf('Order #%d', $order->get_id());
    }

    /**
     * Format order date
     * @param WC_DateTime|null $date
     * @return string
     */
    private function format_order_date($date): string
    {
        if (!$date) {
            return '';
        }
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Extract order items
     * @param WC_Order $order
     * @return array
     */
    private function get_order_items($order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = $this->format_order_item($item);
        }
        return $items;
    }

    /**
     * Format address data
     * @param array $address
     * @return string
     */
    private function format_address(array $address): string
    {
        $parts = [];
        if (!empty($address['address_1'])) {
            $parts[] = $address['address_1'];
        }
        if (!empty($address['address_2'])) {
            $parts[] = $address['address_2'];
        }
        if (!empty($address['city'])) {
            $parts[] = $address['city'];
        }
        if (!empty($address['state'])) {
            $parts[] = $address['state'];
        }
        if (!empty($address['postcode'])) {
            $parts[] = $address['postcode'];
        }
        if (!empty($address['country'])) {
            $parts[] = $address['country'];
        }
        return implode(', ', $parts);
    }

    /**
     * Format single order item
     * @param WC_Order_Item $item
     * @return array
     */
    private function format_order_item($item): array
    {
        return [
            'product_id' => $item->get_product_id(),
            'name'       => $item->get_name(),
            'quantity'   => $item->get_quantity(),
            'total'      => $item->get_total(),
        ];
    }
}
