<?php
/**
 * Base REST Controller
 *
 * @package DirectReach_Campaign_Builder
 * @since 2.0.0
 */

namespace DirectReach\CampaignBuilder\API;

if (!defined('ABSPATH')) {
    exit;
}

abstract class REST_Controller {
    
    /**
     * REST API namespace
     */
    protected $namespace = 'directreach/v2';
    
    /**
     * Register routes
     */
    abstract public function register_routes();
    
    /**
     * Permission callback for all directreach/v2 endpoints.
     *
     * Accepts either:
     *   1. WordPress logged-in user with manage_options (admin UI)
     *   2. X-API-Key header matching directreach_journeyos_api_key (JourneyOS)
     *   3. X-API-Key header matching cpd_api_key (JourneyOS WORDPRESS_API_KEY)
     */
    public function check_permissions() {
        error_log('[CB_AUTH] check_permissions called');

        if (current_user_can('manage_options')) {
            error_log('[CB_AUTH] Cookie auth passed');
            return true;
        }

        $provided_key = isset($_SERVER['HTTP_X_API_KEY']) ? sanitize_text_field($_SERVER['HTTP_X_API_KEY']) : '';
        error_log('[CB_AUTH] Provided key: ' . (!empty($provided_key) ? substr($provided_key, 0, 8) . '...' : 'EMPTY'));

        if (empty($provided_key)) {
            error_log('[CB_AUTH] No key provided - denying');
            return false;
        }

        # Check directreach_journeyos_api_key (7a0e8131...)
        $journeyos_key = get_option('directreach_journeyos_api_key', '');
        if (!empty($journeyos_key) && hash_equals($journeyos_key, $provided_key)) {
            error_log('[CB_AUTH] Passed via directreach_journeyos_api_key');
            return true;
        }

        # Check cpd_api_key (6b2d45a3...)
        $cpd_key = get_option('cpd_api_key', '');
        if (!empty($cpd_key) && hash_equals($cpd_key, $provided_key)) {
            error_log('[CB_AUTH] Passed via cpd_api_key');
            return true;
        }

        error_log('[CB_AUTH] All auth methods failed - denying');
        return false;
    }
}
