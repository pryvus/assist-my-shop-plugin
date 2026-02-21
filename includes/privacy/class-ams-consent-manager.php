<?php

/**
 * Consent Manager
 * 
 * Orchestrates consent detection across multiple cookie consent plugins
 * Uses adapter pattern to support Complianz, CookieYes, and Moove
 */
class AMS_Consent_Manager
{
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Array of consent adapters
     */
    private $adapters = [];

    /**
     * Get singleton instance
     * @return AMS_Consent_Manager
     */
    public static function get_instance(): AMS_Consent_Manager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - initialize adapters
     */
    private function __construct()
    {
        $this->register_adapters();
    }

    /**
     * Register all available consent adapters
     */
    private function register_adapters(): void
    {
        $this->adapters[] = new AMS_Complianz_Adapter();
        $this->adapters[] = new AMS_CookieYes_Adapter();
        $this->adapters[] = new AMS_Moove_Adapter();

        // Allow custom adapters to be registered
        do_action('ams_register_consent_adapters', $this);
    }

    /**
     * Register a custom consent adapter
     * @param AMS_Consent_Adapter_Interface $adapter
     */
    public function register_adapter(AMS_Consent_Adapter_Interface $adapter): void
    {
        $this->adapters[] = $adapter;
    }

    /**
     * Detect customer consent from active plugins
     * Tries each adapter in sequence until one finds consent
     * 
     * @param WC_Order $order
     * @return bool True if consent detected, false otherwise
     */
    public function detect_consent(WC_Order $order): bool
    {
        // First check for explicit consent meta
        $explicit_consent = $order->get_meta('_privacy_consent');
        if ($explicit_consent !== '') {
            return (bool) $explicit_consent;
        }

        // Try each registered adapter
        foreach ($this->adapters as $adapter) {
            if (!$adapter->is_plugin_active()) {
                continue;
            }

            $result = $adapter->detect_consent($order);
            
            // If adapter returned a definitive answer, use it
            if ($result !== null) {
                return $result;
            }
        }

        // Fallback: check order status
        // Completed orders imply consent was given during checkout
        $status = $order->get_status();
        return in_array($status, ['completed', 'processing', 'on-hold']);
    }

    /**
     * Get which adapters are active
     * @return array Array of active plugin names
     */
    public function get_active_adapters(): array
    {
        $active = [];
        foreach ($this->adapters as $adapter) {
            if ($adapter->is_plugin_active()) {
                $active[] = $adapter->get_plugin_name();
            }
        }
        return $active;
    }

    /**
     * Get all registered adapters
     * @return AMS_Consent_Adapter_Interface[]
     */
    public function get_adapters(): array
    {
        return $this->adapters;
    }

    /**
     * Get consent meta keys from all adapters
     * Useful for debugging and logging
     * 
     * @return array Flat array of all meta keys
     */
    public function get_all_meta_keys(): array
    {
        $keys = [];
        foreach ($this->adapters as $adapter) {
            $keys = array_merge($keys, $adapter->get_consent_meta_keys());
        }
        return array_unique($keys);
    }

    /**
     * Save consent to order meta after checkout
     * Called by hooks from consent plugins
     * 
     * @param int $order_id
     * @param bool $consent
     * @param string $source Plugin name or source identifier
     */
    public function save_consent(int $order_id, bool $consent, string $source = 'direct'): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Save explicit consent
        $order->update_meta_data('_privacy_consent', $consent);
        
        // Save source for logging
        $order->update_meta_data('_privacy_consent_source', $source);
        
        // Save timestamp
        $order->update_meta_data('_privacy_consent_date', current_time('mysql'));
        
        $order->save();
    }

    /**
     * Get consent details for an order
     * @param WC_Order $order
     * @return array Consent info including source, date, and active adapters
     */
    public function get_consent_info(WC_Order $order): array
    {
        return [
            'has_consent'     => $this->detect_consent($order),
            'explicit_consent' => (bool) $order->get_meta('_privacy_consent'),
            'consent_source'  => $order->get_meta('_privacy_consent_source') ?: 'auto-detected',
            'consent_date'    => $order->get_meta('_privacy_consent_date') ?: '',
            'active_adapters' => $this->get_active_adapters(),
        ];
    }
}
