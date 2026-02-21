<?php

/**
 * Consent Adapter Interface
 * 
 * Defines contract for detecting consent from various cookie consent plugins
 */
interface AMS_Consent_Adapter_Interface
{
    /**
     * Check if the consent plugin is active
     * @return bool
     */
    public function is_plugin_active(): bool;

    /**
     * Detect if customer has given consent
     * @param WC_Order $order
     * @return bool|null True if consented, False if denied, null if not detected
     */
    public function detect_consent(WC_Order $order): ?bool;

    /**
     * Get plugin name/identifier
     * @return string
     */
    public function get_plugin_name(): string;

    /**
     * Get consent meta keys used by this plugin
     * @return array
     */
    public function get_consent_meta_keys(): array;
}
