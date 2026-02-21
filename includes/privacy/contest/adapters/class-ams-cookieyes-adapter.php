<?php

/**
 * CookieYes Consent Adapter
 * 
 * Integrates with CookieYes | GDPR Cookie Consent plugin
 * https://www.cookieyes.com/
 */
class AMS_CookieYes_Adapter implements AMS_Consent_Adapter_Interface
{
    /**
     * Check if CookieYes plugin is active
     * @return bool
     */
    public function is_plugin_active(): bool
    {
        return function_exists('cookieyes_get_consent_data') || defined('COOKIEYES_PLUGIN_FILE');
    }

    /**
     * Detect consent from CookieYes
     * @param WC_Order $order
     * @return bool|null
     */
    public function detect_consent(WC_Order $order): ?bool
    {
        if (!$this->is_plugin_active()) {
            return null;
        }

        // Try to get from order meta (saved at checkout)
        $stored_consent = $order->get_meta('_cookieyes_consent');
        if ($stored_consent !== '') {
            return (bool) $stored_consent;
        }

        // Try to get preferences from meta
        $preferences = $order->get_meta('_cookieyes_preferences');
        if (!empty($preferences) && is_array($preferences)) {
            // Check if analytics or marketing is enabled
            $has_analytics = isset($preferences['analytics']) && $preferences['analytics'];
            $has_marketing = isset($preferences['marketing']) && $preferences['marketing'];
            
            if ($has_analytics || $has_marketing) {
                return true;
            }
        }

        // CookieYes stores consent in transients for anonymous users
        // For orders, we assume completion implies consent unless explicitly refused
        $status = $order->get_status();
        
        // If order was completed/processing, it implies the customer was presented consent before checkout
        if (in_array($status, ['completed', 'processing', 'on-hold'])) {
            return true;
        }

        return null;
    }

    /**
     * Get plugin identifier
     * @return string
     */
    public function get_plugin_name(): string
    {
        return 'CookieYes';
    }

    /**
     * Get meta keys used by CookieYes
     * @return array
     */
    public function get_consent_meta_keys(): array
    {
        return [
            '_cookieyes_consent',
            '_cookieyes_preferences',
            'cookieyes_consent_analytics',
            'cookieyes_consent_marketing',
        ];
    }
}
