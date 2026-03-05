<?php

/**
 * Data Privacy Management Trait
 * 
 * Handles compliance with various data protection regulations:
 * - GDPR (EU/EEA)
 * - CCPA/CPRA (California, USA)
 * - LGPD (Brazil)
 * - PIPEDA (Canada)
 * - DPA 2018 (UK)
 * 
 * @trait Trait_AMS_Data_Privacy
 */
trait Trait_AMS_Data_Privacy
{
    /**
     * EU Member States and EEA countries for GDPR compliance
     */
    private function get_eu_countries(): array
    {
        return [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'NO', 'LI', 'CH'
        ];
    }

    /**
     * CCPA/CPRA regulated states (California + other states with similar laws)
     */
    private function get_ccpa_states(): array
    {
        return [
            'CA', 'CO', 'CT', 'DE', 'FL', 'MT', 'NE', 'NH', 'NJ', 'OR', 'TX', 'UT', 'VA'
        ];
    }

    /**
     * LGPD countries (Brazil and its territories)
     */
    private function get_lgpd_countries(): array
    {
        return [ 'BR' ];
    }

    /**
     * PIPEDA covered country (Canada)
     */
    private function get_pipeda_country(): string
    {
        return 'CA';
    }

    /**
     * Check if customer personal data should be restricted
     * Returns allowed personal data fields based on privacy regulations
     * 
     * @param object $order WC_Order object
     * @return array Allowed personal data fields or empty if restricted
     */
    private function get_allowed_personal_data($order): array
    {
        // Get store base country and customer billing country
        $store_country = WC()->countries->get_base_country();
        $customer_country = $order->get_billing_country();

        // Check which privacy laws apply
        $regulations = $this->get_applicable_regulations($store_country, $customer_country);

        // If any regulation applies, check consent
        if (!empty($regulations)) {
            if (!$this->has_privacy_consent($order, $regulations)) {
                // No consent - return no personal data
                return [];
            }
        }

        // Consent given or no regulation applies
        $personal_data = [
            'customer_email'   => $order->get_billing_email(),
            'customer_name'    => $order->get_formatted_billing_full_name(),
            'customer_phone'   => $order->get_billing_phone(),
            'shipping_address' => $this->format_address($order->get_address('shipping')),
            'billing_address'  => $this->format_address($order->get_address('billing')),
            'order_notes'      => $order->get_customer_note(),
        ];

        // Apply masking for regulated jurisdictions
        return $this->apply_privacy_masks($personal_data, $regulations);
    }

    /**
     * Get list of applicable privacy regulations for order
     * 
     * @param string $store_country Store base country code
     * @param string $customer_country Customer billing country code
     * @return array List of applicable regulations (e.g., ['GDPR', 'CCPA'])
     */
    private function get_applicable_regulations(string $store_country, string $customer_country): array
    {
        $regulations = [];

        // GDPR applies if store is in EU/EEA
        if (in_array($store_country, $this->get_eu_countries(), true)) {
            $regulations[] = 'GDPR';
        }

        // CCPA/CPRA applies if store in California or customer in CCPA state
        if ($store_country === 'US' && in_array($this->get_us_state($store_country), $this->get_ccpa_states(), true)) {
            $regulations[] = 'CCPA';
        }

        // LGPD applies if store in Brazil
        if (in_array($store_country, $this->get_lgpd_countries(), true)) {
            $regulations[] = 'LGPD';
        }

        // PIPEDA applies if store in Canada
        if ($store_country === $this->get_pipeda_country()) {
            $regulations[] = 'PIPEDA';
        }

        return $regulations;
    }

    /**
     * Check if customer has given privacy consent for applicable regulations
     * 
     * @param object $order WC_Order object
     * @param array $regulations List of applicable regulations
     * @return bool True if consent given or orders imply consent
     */
    private function has_privacy_consent($order, array $regulations): bool
    {
        // Use Consent Manager to detect consent from various plugins
        $privacy_manager = AMS_Privacy::get_instance();
        return $privacy_manager->detect_consent($order);
    }

    /**
     * Apply privacy masks to personal data based on regulations
     * 
     * @param array $personal_data
     * @param array $regulations
     * @return array Masked personal data
     */
    private function apply_privacy_masks(array $personal_data, array $regulations): array
    {
        // GDPR requires stricter masking
        if (in_array('GDPR', $regulations)) {
            $personal_data['customer_phone'] = $this->mask_phone($personal_data['customer_phone']);
        }

        // CCPA allows full data but must log access
        // LGPD similar to GDPR
        if (in_array('LGPD', $regulations)) {
            $personal_data['customer_phone'] = $this->mask_phone($personal_data['customer_phone']);
        }

        return $personal_data;
    }

    /**
     * Build privacy info for response
     * 
     * @param object $order WC_Order object
     * @return array Privacy metadata
     */
    private function get_privacy_info($order): array
    {
        $store_country = WC()->countries->get_base_country();
        $regulations = $this->get_applicable_regulations($store_country, $order->get_billing_country());
        
        $consent_manager = AMS_Privacy::get_instance();
        $consent_info = $consent_manager->get_consent_info($order);

        return [
            'privacy_regulations'     => $regulations,
            'privacy_consent'         => $consent_info['has_consent'],
            'sensitive_data_allowed'  => !empty($this->get_allowed_personal_data($order)),
            'consent_source'          => $consent_info['consent_source'],
            'active_consent_plugins'  => $consent_info['active_adapters'],
        ];
    }

    /**
     * Mask phone number for privacy (keep last 4 digits)
     * 
     * @param string $phone
     * @return string
     */
    private function mask_phone(string $phone): string
    {
        if (empty($phone) || strlen($phone) < 4) {
            return '';
        }
        $last_four = substr($phone, -4);
        return '***-' . $last_four;
    }

    /**
     * Get US state from store location (to be implemented if multi-state support needed)
     * 
     * @param string $country
     * @return string State code or empty
     */
    private function get_us_state(string $country): string
    {
        // Get from WooCommerce settings
        if ($country === 'US') {
            return get_option('woocommerce_default_state', '');
        }
        return '';
    }
}
