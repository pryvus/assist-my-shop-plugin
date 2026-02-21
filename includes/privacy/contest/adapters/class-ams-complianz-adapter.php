<?php

/**
 * Complianz Consent Adapter
 * 
 * Integrates with Complianz – GDPR/CCPA Cookie Consent plugin
 * https://www.complianz.io/
 */
class AMS_Complianz_Adapter implements AMS_Consent_Adapter_Interface
{
    /**
     * Check if Complianz plugin is active
     * @return bool
     */
    public function is_plugin_active(): bool
    {
        return function_exists('cmplz_user_has_consented') && defined('COMPLIANZ_PLUGIN');
    }

    /**
     * Detect consent from Complianz
     * @param WC_Order $order
     * @return bool|null
     */
    public function detect_consent(WC_Order $order): ?bool
    {
        if (!$this->is_plugin_active()) {
            return null;
        }

        // Try to get from order meta first
        $stored_consent = $order->get_meta('_cmplz_consent');
        if ($stored_consent !== '') {
            return (bool) $stored_consent;
        }

        // Try to check user consent (if user is logged in)
        $customer_id = $order->get_customer_id();
        if ($customer_id > 0 && function_exists('cmplz_user_has_consented')) {
            // Check marketing consent (primary for data usage)
            $marketing = cmplz_user_has_consented('marketing');
            
            // Also check stats consent
            $stats = cmplz_user_has_consented('statistics');
            
            // If either marketing or stats is consented, allow data processing
            return $marketing || $stats;
        }

        return null;
    }

    /**
     * Get plugin identifier
     * @return string
     */
    public function get_plugin_name(): string
    {
        return 'Complianz';
    }

    /**
     * Get meta keys used by Complianz
     * @return array
     */
    public function get_consent_meta_keys(): array
    {
        return [
            '_cmplz_consent',
            '_cmplz_preferences',
            'cmplz_consent_marketing',
            'cmplz_consent_statistics',
            'cmplz_consent_functional',
        ];
    }
}
