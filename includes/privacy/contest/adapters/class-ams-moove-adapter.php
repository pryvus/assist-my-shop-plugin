<?php

/**
 * Moove Consent Adapter
 * 
 * Integrates with GDPR Cookie Compliance (Moove) plugin
 * https://www.moove.io/gdpr-cookie-compliance/
 */
class AMS_Moove_Adapter implements AMS_Consent_Adapter_Interface
{
    /**
     * Check if Moove plugin is active
     * @return bool
     */
    public function is_plugin_active(): bool
    {
        return defined('MOOVE_GDPR_ACTIVE') || function_exists('moove_gdpr_get_user_consent');
    }

    /**
     * Detect consent from Moove
     * @param WC_Order $order
     * @return bool|null
     */
    public function detect_consent(WC_Order $order): ?bool
    {
        if (!$this->is_plugin_active()) {
            return null;
        }

        // Try to get from order meta first
        $stored_consent = $order->get_meta('_moove_consent');
        if ($stored_consent !== '') {
            return (bool) $stored_consent;
        }

        // Try individual consent meta fields
        $marketing_consent = $order->get_meta('_moove_consent_marketing');
        $analytics_consent = $order->get_meta('_moove_consent_analytics');
        
        if ($marketing_consent !== '' || $analytics_consent !== '') {
            // If either is explicitly set to true, allow
            if ($marketing_consent || $analytics_consent) {
                return true;
            }
            // If either is explicitly set to false, deny
            if ($marketing_consent === '0' || $analytics_consent === '0') {
                return false;
            }
        }

        // Try to check user consent (if user is logged in)
        $customer_id = $order->get_customer_id();
        if ($customer_id > 0 && function_exists('moove_gdpr_get_user_consent')) {
            $user_consent = moove_gdpr_get_user_consent($customer_id);
            if (!empty($user_consent)) {
                // Check if marketing or statistics consent exists
                $marketing = isset($user_consent['moove-gdpr-marketing']) && $user_consent['moove-gdpr-marketing'];
                $analytics = isset($user_consent['moove-gdpr-analytics']) && $user_consent['moove-gdpr-analytics'];
                
                if ($marketing || $analytics) {
                    return true;
                }
            }
        }

        return null;
    }

    /**
     * Get plugin identifier
     * @return string
     */
    public function get_plugin_name(): string
    {
        return 'Moove';
    }

    /**
     * Get meta keys used by Moove
     * @return array
     */
    public function get_consent_meta_keys(): array
    {
        return [
            '_moove_consent',
            '_moove_consent_marketing',
            '_moove_consent_analytics',
            '_moove_consent_thirdparties',
            'gdpr_cookie_consent',
        ];
    }
}
